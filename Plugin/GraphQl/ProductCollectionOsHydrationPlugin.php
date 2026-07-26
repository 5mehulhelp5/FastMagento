<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\GraphQl;

use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Store\Model\ScopeInterface;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * OS-serve the GraphQL storefront product collection (products(), category products, etc).
 *
 * `Magento\CatalogGraphQl\...\DataProvider\Product::getList()` and `ProductSearch::getList()`
 * both build a plain `Catalog\Model\ResourceModel\Product\Collection`, apply every
 * filter/sort/paging step, then call `$collection->load()` — which pulls every item straight
 * from MySQL EAV, so each item's price resolvers (TierPrice/CatalogRulePrice/
 * LowestPriceOptionsProvider — see the `frontend`-area preferences of the same name) fall
 * through to SQL instead of the indexed doc. Measured: a 20-item search result with a mix of
 * configurables cost 128 queries (20x catalogrule_product_price + 20x
 * catalog_product_entity_tier_price, the per-item "from" price N+1).
 *
 * This `around` on `load()` re-derives the collection's already-resolved target ids (same
 * filter/sort/paging, reduced to just the id column — one query), batch-fetches their OS docs,
 * and — only when EVERY id is fully servable — replaces the native EAV load with OS-hydrated
 * `ShellNoEavProduct` shells. Those shells are what makes the `frontend`-area price/stock/
 * configurable preferences (also wired for `graphql` in etc/graphql/di.xml) serve from the
 * indexed doc instead of SQL, eliminating the N+1 the same way the storefront PDP/PLP does.
 *
 * SAFETY: "correct over fast, always". Any Throwable, any missing/partial doc, wrong area, the
 * flag off, or an already-loaded collection -> the WHOLE load falls straight back to
 * `$proceed(...)` (100% native, byte-for-byte identical to stock Magento). Never half-serves a
 * result page — one unservable id sends the entire page through the native path.
 */
class ProductCollectionOsHydrationPlugin
{
    private const XML_PATH_OS_SERVE = 'fastmagento/graphql/os_serve_products';

    public function __construct(
        private readonly AppState $appState,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OpenSearchPdpFetcher $osFetcher,
        private readonly ShellProductBuilder $shellBuilder,
        private readonly ManagerInterface $eventManager,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * @param ProductCollection $subject
     * @param callable $proceed
     * @param bool $printQuery
     * @param bool $logQuery
     * @return ProductCollection
     */
    public function aroundLoad(ProductCollection $subject, callable $proceed, $printQuery = false, $logQuery = false)
    {
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_GRAPHQL
                || !$this->scopeConfig->isSetFlag(self::XML_PATH_OS_SERVE, ScopeInterface::SCOPE_STORE)
                || $subject->isLoaded()
                || (int) $subject->getPageSize() > 0
            ) {
                // getPageSize() > 0 means pagination was set via the collection's DEFERRED
                // _pageSize (rendered to the select only inside native load()) rather than baked
                // into the select as the search-engine id slice. In every GraphQL product path on
                // an OpenSearch-backed store (products()/category products, search or filter) the
                // engine resolves sort+paging and SearchResultApplier stamps the sliced ids onto
                // the select as `entity_id IN (...) ORDER BY FIELD(...)` BEFORE load() — so
                // getPageSize() is 0 and resolveTargetIds() reads the correct, ordered slice.
                // If it is ever >0, we can't trust the cloned select to already carry sort/limit,
                // so we defer to native (correct over fast).
                return $proceed($printQuery, $logQuery);
            }

            $ids = $this->resolveTargetIds($subject);
            if (!$ids) {
                return $proceed($printQuery, $logQuery);
            }

            $docs = $this->osFetcher->fetchByIds($ids);

            // Whole-collection servability gate: never half-serve a result page. Any id
            // missing from the index, or lacking the bare minimum to render correctly, sends
            // the ENTIRE collection through the native path instead.
            foreach ($ids as $id) {
                if (!$this->isDocServable($docs[$id] ?? null)) {
                    return $proceed($printQuery, $logQuery);
                }
            }

            return $this->hydrateFromOs($subject, $ids, $docs);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                'GraphQL OS product-collection serve failed; native fallback: ' . $e->getMessage()
            );
            return $proceed($printQuery, $logQuery);
        }
    }

    /**
     * Resolve the collection's target entity ids IN its already-applied filter/sort/paging —
     * clone the fully-built Select and reduce it to just the id column (one query) instead of
     * letting native load() pull every joined EAV/price/stock column for every row.
     *
     * @return int[]
     */
    private function resolveTargetIds(ProductCollection $subject): array
    {
        $select = clone $subject->getSelect();
        $select->reset(Select::COLUMNS);
        $select->columns('e.entity_id');
        $ids = $subject->getConnection()->fetchCol($select);

        return array_values(array_map('intval', $ids));
    }

    /**
     * Is a doc complete enough to hydrate a listing-safe shell? Conservative on purpose:
     * anything uncertain returns false and the whole page goes native.
     *
     * @param array<string, mixed>|null $doc
     */
    private function isDocServable(?array $doc): bool
    {
        if ($doc === null || empty($doc['sku']) || empty($doc['name'])) {
            return false;
        }

        return isset($doc['status']) && (int) $doc['status'] === ProductStatus::STATUS_ENABLED;
    }

    /**
     * Build shells in id order (preserving the collection's resolved sort) and add them
     * directly to the collection — no load(), no product/price/stock SQL.
     *
     * @param int[] $ids
     * @param array<int, array<string, mixed>> $docs
     */
    private function hydrateFromOs(ProductCollection $subject, array $ids, array $docs): ProductCollection
    {
        foreach ($ids as $id) {
            $shell = $this->shellBuilder->buildNoEavProductFromOsDoc($docs[$id], true);
            $shell->setStoreId($subject->getStoreId());
            // Core's load() calls $object->setOrigData() (a full copy of _data) after
            // populating each row (see AbstractDb::load()); ShellNoEavProduct::load() is a
            // no-op so that never runs. Without it, getOrigData($linkField) is empty — and
            // GraphQL's post-load CollectionPostProcessor keys its media_gallery(_entries)/
            // options re-fetch off exactly that value, which would otherwise wipe a requested
            // gallery/options field back to empty instead of correctly re-querying it natively.
            $shell->setOrigData();
            $subject->addItem($shell);
        }

        // addItem()/isLoaded() live on Magento\Framework\Data\Collection; _setIsLoaded is
        // protected there. A bound closure reaches it without reflection (same trick the
        // proven Quote\Item\Collection OS-serve path uses).
        (function () {
            $this->_setIsLoaded(true);
        })->call($subject);

        // Mirror core Product\Collection::_afterLoad()'s ONLY dispatched event so any observer
        // still fires. Verified against core: `prepare_catalog_product_collection_prices` is
        // NOT part of this class's lifecycle at all — it belongs to
        // Quote\Model\ResourceModel\Quote\Item\Collection and Bundle\Model\Product\Price, so
        // firing it here would be non-standard and could mislead a listener expecting a
        // cart/bundle context. `catalog_product_collection_load_after` is the real one, fired
        // under the same `count() > 0` guard core uses.
        if (count($subject)) {
            $this->eventManager->dispatch('catalog_product_collection_load_after', ['collection' => $subject]);
        }

        return $subject;
    }
}
