<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Plp;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use Psr\Log\LoggerInterface;

/**
 * Serves the category/search product listing from OpenSearch instead of EAV.
 *
 * WHY THIS IS SMALL
 * -----------------
 * On Magento 2.4 with an OpenSearch/Elasticsearch engine, a category page already builds its
 * listing through `Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection`, so the
 * **id list, sort order, pagination and layered-navigation facet counts already come from
 * OpenSearch**. What still costs hundreds of queries is the last step: turning those ids into
 * product objects through EAV — the attribute-value loads plus the per-product fan-out for tier
 * price, catalog rule price, media gallery and stock.
 *
 * So this does not reimplement layered navigation or the toolbar. It replaces exactly one thing:
 * entity hydration. Everything above it — `catalog.leftnav`, the toolbar, sorting, pagination,
 * and every stock block and template on any theme — keeps working untouched, because the
 * collection still ends up holding real `Magento\Catalog\Model\Product` instances.
 *
 * DEGRADATION
 * -----------
 * The native path is used whenever this cannot be *fully* served: a disabled toggle, a
 * non-frontend area, an empty page, any id missing from the index, or any exception. Partial
 * hydration is never attempted — a listing showing some products with index data and others with
 * EAV data would be far worse than simply being slower.
 */
class ListingHydrator
{
    public const XML_PATH_ENABLED = 'fastmagento/plp/serve_listing';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OpenSearchPdpFetcher $fetcher,
        private readonly ShellProductBuilder $shellBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Try to populate $collection's items from OpenSearch.
     *
     * @return bool true when the collection was fully populated (caller must skip the native
     *              entity load), false when the caller should fall through to EAV.
     */
    public function hydrate(ProductCollection $collection): bool
    {
        try {
            $ids = $this->resolvePageIds($collection);
            if (!$ids) {
                // No rows for this page. Let the native path produce the empty collection so the
                // "no products" messaging and any third-party after-load logic behave normally.
                return false;
            }

            $docs = $this->fetcher->fetchByIds($ids);

            // All-or-nothing: a single missing doc means the index is behind the catalogue, and a
            // half-indexed listing is a correctness problem, not a performance one.
            $missing = array_diff($ids, array_keys($docs));
            if ($missing) {
                $this->logger->warning(sprintf(
                    '[FastMagento] PLP fell back to EAV: %d of %d product(s) not in the index (%s). '
                    . 'Run: bin/magento indexer:reindex fastmagento_product',
                    count($missing),
                    count($ids),
                    implode(',', array_slice($missing, 0, 10))
                ));
                return false;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();
            $products = [];
            foreach ($ids as $id) {
                // hydrateChildren = TRUE, counter-intuitively. A grid card looks like it only
                // needs the parent, but Magento's price rendering asks each configurable for its
                // children to compute the "as low as" range, and its stock status is derived from
                // them too. Leaving them out does not save the work — it just pushes it back to
                // MySQL one product at a time, which measured *worse* than hydrating them here
                // (123 vs 15 product queries on a 12-configurable page).
                $product = $this->shellBuilder->buildNoEavProductFromOsDoc($docs[$id], true);
                if (!$product || !$product->getId()) {
                    return false;
                }
                $product->setStoreId($storeId);
                $products[] = $product;
            }

            // Only mutate the collection once every product is built, so a failure halfway through
            // leaves it untouched for the native path.
            foreach ($products as $product) {
                $collection->addItem($product);
            }

            // `_loadAttributes()` returns immediately when nothing is selected, which is what stops
            // the EAV attribute pass from running behind us and overwriting the indexed values.
            $collection->removeAttributeToSelect();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[FastMagento] PLP hydration failed, using native EAV: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The ids for the current page, in the order the search engine returned them.
     *
     * Read back off the collection's own SELECT rather than reaching into the search result:
     * by this point the select already carries the engine's `entity_id IN (...)`, its
     * `ORDER BY FIELD(...)` relevance ordering, every filter any third-party module added, and
     * the page limit. Re-using it means one narrow id-only query and no assumptions about
     * private state — and it stays correct no matter what else touched the collection.
     *
     * @return int[]
     */
    private function resolvePageIds(ProductCollection $collection): array
    {
        $select = clone $collection->getSelect();
        $select->reset(Select::COLUMNS);
        $select->columns(['entity_id' => 'e.entity_id']);

        $ids = [];
        foreach ($collection->getConnection()->fetchCol($select) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
