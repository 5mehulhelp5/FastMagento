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
use ParkkTech\FastMagento\Model\Plp\FallbackRecorder;
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
    private const XML_PATH_SERVE_PAGE_IDS = 'fastmagento/plp/serve_page_ids';
    private const XML_PATH_PAGE_ID_PARITY = 'fastmagento/plp/page_id_parity_check';

    /** Set while deriving ids when the select carried Magento's out-of-stock filter. */
    private bool $sawStockPredicate = false;

    /** Whether hydrate() still owes that filter, applied from the indexed documents. */
    private bool $mustFilterInStock = false;

    public const XML_PATH_ENABLED = 'fastmagento/plp/serve_listing';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OpenSearchPdpFetcher $fetcher,
        private readonly ShellProductBuilder $shellBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly FallbackRecorder $fallbackRecorder
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
                return false;
            }
            return $this->fill($collection, $ids, $this->mustFilterInStock);
        } catch (\Throwable $e) {
            $this->logger->error('[FastMagento] PLP hydration failed, using native EAV: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Hydrate a collection from the index for ids the CALLER has already resolved (widget and
     * slider collections, whose ids come from CategoryWidgetServer). $filterInStock says whether
     * the native query would have hidden out-of-stock products, so the documents' is_in_stock
     * is applied the same way. Same all-or-nothing contract as hydrate(): FALSE means "not
     * touched, run the native load".
     *
     * @param int[] $ids
     */
    public function hydrateWithIds(ProductCollection $collection, array $ids, bool $filterInStock): bool
    {
        try {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!$ids) {
                return false;
            }
            return $this->fill($collection, $ids, $filterInStock);
        } catch (\Throwable $e) {
            $this->logger->error('[FastMagento] widget hydration failed, using native EAV: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch the documents for $ids, apply the owed stock filter, build shell products and put
     * them into the collection. FALSE = index cannot answer for this page, leave it native.
     *
     * @param int[] $ids
     */
    private function fill(ProductCollection $collection, array $ids, bool $mustFilterInStock): bool
    {
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
                $this->fallbackRecorder->record(sprintf(
                    '%d of %d product(s) missing from the index (index behind the catalogue — '
                    . 'reindex fastmagento_product and make sure Magento cron runs)',
                    count($missing),
                    count($ids)
                ));
                return false;
            }

            // Out-of-stock filter, owed from the id derivation.
            //
            // When the ids came from the select instead of from MySQL, Magento's
            // `stock_status_index.stock_status = 1` predicate was skipped rather than evaluated —
            // so apply it here, from the same documents we just fetched. Doing it after the
            // all-or-nothing check above matters: a product missing from the INDEX is an index
            // problem and must still fall back, whereas a product the index says is out of stock
            // is a real answer and simply does not belong on the page.
            if ($mustFilterInStock) {
                $inStock = [];
                foreach ($ids as $id) {
                    $doc = $docs[$id] ?? null;
                    if ($doc !== null && !empty($doc['is_in_stock'])) {
                        $inStock[] = $id;
                    }
                }
                if (!$inStock) {
                    // Everything on this page is out of stock. Let the native path build the
                    // empty collection so "no products" messaging behaves normally.
                    return false;
                }
                $ids = $inStock;
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
                    $this->fallbackRecorder->record(sprintf(
                        'product %d has an index doc that could not build a shell product '
                        . '(reindex fastmagento_product)',
                        (int) $id
                    ));
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
        $derived = null;
        if ($this->scopeConfig->isSetFlag(self::XML_PATH_SERVE_PAGE_IDS, ScopeInterface::SCOPE_STORE)) {
            $derived = $this->derivePageIdsWithoutSql($collection);
        }

        // Parity mode: run BOTH and log any disagreement. Off by default — it deliberately
        // performs the very query the derivation exists to avoid, so it is a diagnostic for
        // validating a store's sorts and filters, not something to leave on.
        if ($derived !== null && $this->scopeConfig->isSetFlag(self::XML_PATH_PAGE_ID_PARITY, ScopeInterface::SCOPE_STORE)) {
            $sqlIds = $this->fetchPageIdsViaSql($collection);
            if ($sqlIds !== $derived) {
                $this->logger->warning(sprintf(
                    '[FastMagento] page-id parity MISMATCH. sql=[%s] derived=[%s]. '
                    . 'Serving the SQL ids. Disable fastmagento/plp/serve_page_ids if this repeats.',
                    implode(',', $sqlIds),
                    implode(',', $derived)
                ));

                return $sqlIds;
            }
            $this->logger->info('[FastMagento] page-id parity OK (' . count($derived) . ' ids)');
        }

        if ($derived !== null) {
            $this->mustFilterInStock = $this->sawStockPredicate;

            return $derived;
        }

        $this->mustFilterInStock = false;   // the SQL applied the stock filter itself

        return $this->fetchPageIdsViaSql($collection);
    }

    /**
     * The original SQL path: clone the collection's select and ask MySQL which ids are on the page.
     */
    /**
     * The ids the collection's own SELECT would return, as one index-only query (no e.*, no
     * attribute loads): the parity-safe id source for widget collections, where MySQL's
     * undefined order among equal sort keys cannot be reproduced by the search index.
     *
     * @return int[]
     */
    public function idsViaSql(ProductCollection $collection): array
    {
        return $this->fetchPageIdsViaSql($collection);
    }

    private function fetchPageIdsViaSql(ProductCollection $collection): array
    {
        $select = clone $collection->getSelect();
        // The EAV collection applies its page size inside _loadEntities(); a caller hooked
        // before that point has to apply it here or the id list would be the whole category.
        if (!$select->getPart(Select::LIMIT_COUNT) && (int) $collection->getPageSize() > 0) {
            $select->limitPage(max(1, (int) $collection->getCurPage()), (int) $collection->getPageSize());
        }
        $select->reset(Select::COLUMNS);
        $select->columns(['entity_id' => 'e.entity_id']);

        $ids = [];
        foreach ($collection->getConnection()->fetchCol($select) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Derive the page's ids from the Select WITHOUT executing it — or return null if we cannot.
     *
     * WHY THIS IS POSSIBLE AT ALL
     * ---------------------------
     * By the time the collection loads, Magento has already asked the search engine for this page
     * and applied the answer to the select: the matching ids as `e.entity_id IN (...)`, and, when
     * the engine decided the ORDER (relevance, position), an `ORDER BY FIELD(e.entity_id, ...)`
     * that spells that order out literally. Both are data sitting in the Select object. Running the
     * query re-derives an answer the select is already carrying.
     *
     * WHEN WE MUST NOT
     * ----------------
     * Returns null — meaning "run the SQL" — whenever anything in the select could change WHICH
     * ids survive or in WHAT order, and we cannot reproduce it in PHP:
     *
     *   - any ORDER BY that is not the engine's FIELD() list (sort by price, name, or any joined
     *     column is resolved by MySQL against data we are not looking at here);
     *   - any WHERE beyond the id list (a layered-nav price filter, a stock filter, a
     *     customer-group price-index condition — each can EXCLUDE ids the engine returned);
     *   - GROUP/HAVING/DISTINCT, or a join we do not recognise.
     *
     * Getting this wrong is silent: products in the wrong order, or a filtered-out product
     * appearing on the page. So the bar for deriving is "the select provably does nothing but
     * restrict to a known id list in a known order", and everything else falls back.
     */
    private function derivePageIdsWithoutSql(ProductCollection $collection): ?array
    {
        $this->sawStockPredicate = false;

        try {
            $select = $collection->getSelect();

            if ($select->getPart(Select::GROUP) || $select->getPart(Select::HAVING)) {
                return null;
            }

            $ordered = $this->extractEngineOrder($select);
            $inList = $this->extractIdWhitelist($select);
            if ($inList === null) {
                return null;   // a WHERE we cannot account for, or no id list at all
            }

            if ($ordered === null) {
                // No FIELD() ordering. Only safe when the select imposes no ordering at all;
                // otherwise MySQL is sorting by something we are not reading.
                if ($select->getPart(Select::ORDER)) {
                    return null;
                }
                $ordered = $inList;
            }

            // The engine order may list more ids than the whitelist (paging is applied by LIMIT
            // below); intersect, preserving the engine's order.
            $whitelist = array_flip($inList);
            $ids = [];
            foreach ($ordered as $id) {
                if (isset($whitelist[$id])) {
                    $ids[] = $id;
                }
            }
            if (!$ids) {
                return null;
            }

            $limit = $select->getPart(Select::LIMIT_COUNT);
            $offset = (int) $select->getPart(Select::LIMIT_OFFSET);
            if ($limit) {
                $ids = array_slice($ids, $offset, (int) $limit);
            } elseif ($offset) {
                $ids = array_slice($ids, $offset);
            }

            return $ids ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The engine's explicit ordering, from `ORDER BY FIELD(e.entity_id, 1,2,3)`, or null.
     *
     * @return int[]|null
     */
    private function extractEngineOrder(Select $select): ?array
    {
        $order = $select->getPart(Select::ORDER);
        if (!$order) {
            return null;
        }
        if (count($order) !== 1) {
            return null;   // FIELD() plus a tie-breaker column: MySQL decides, not us
        }

        $expr = is_array($order[0]) ? (string) $order[0][0] : (string) $order[0];
        if (!preg_match('/FIELD\s*\(\s*[`\w.]*entity_id`?\s*,\s*([0-9,\s]+)\)/i', $expr, $m)) {
            return null;
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', $m[1]))));

        return $ids ?: null;
    }

    /**
     * The id whitelist from the select's WHERE, but ONLY when every WHERE clause is an
     * `entity_id IN (...)` restriction. Any other predicate means MySQL is filtering on something
     * we are not evaluating, so we refuse.
     *
     * @return int[]|null
     */
    private function extractIdWhitelist(Select $select): ?array
    {
        $wheres = $select->getPart(Select::WHERE);
        if (!$wheres) {
            return null;
        }

        $ids = null;
        foreach ($wheres as $where) {
            $clause = (string) $where;
            // The one predicate we are willing to account for ourselves. Magento adds
            // `stock_status_index.stock_status = 1` to hide out-of-stock products; every indexed
            // document already carries is_in_stock, so this is a filter we can apply from the
            // documents we are about to fetch anyway rather than a reason to run the query.
            // Recorded here and applied in hydrate() AFTER the docs come back.
            if (preg_match('/^\\s*(AND\\s+)?\\(?\\s*[`\\w.]*stock_status`?\\s*=\\s*.?1.?\\s*\\)?\\s*$/i', $clause)) {
                $this->sawStockPredicate = true;
                continue;
            }

            if (!preg_match('/^\s*(?:AND\s+)?\(?\s*[`\w.]*entity_id`?\s+IN\s*\(([0-9,\s\x27]+)\)\s*\)?\s*$/i', $clause, $m)) {
                return null;
            }
            $these = array_values(array_filter(array_map(
                static fn($v) => (int) trim($v, " '"),
                explode(',', $m[1])
            )));
            $ids = $ids === null ? $these : array_values(array_intersect($ids, $these));
        }

        return $ids ?: null;
    }
}
