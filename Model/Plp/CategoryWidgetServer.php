<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Plp;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Elasticsearch\Model\Adapter\Index\IndexNameResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\DB\Select;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use Psr\Log\LoggerInterface;

/**
 * Serves "products of category N" collections — the shape behind CMS product widgets and
 * theme sliders (Hyvä's ProductList view model, Magento's CatalogWidget with a category
 * condition, "featured"/"new" home-page blocks) — from the indexes instead of MySQL.
 *
 * These are plain product collections, not the search-driven listing collection, so the
 * listing hydrator never sees them. The shape is recognised from the SELECT the collection
 * built: a join on catalog_category_product_index for one category, a visibility list, the
 * usual website/stock/review/price joins, an ORDER BY of position, price or nothing, a LIMIT.
 * The product ids are then resolved from Magento's OWN search index, which carries per-category
 * membership (anchor-inclusive), per-category position and per-customer-group price exactly as
 * the SQL index tables do; the products themselves come from the FastMagento documents via
 * ListingHydrator::hydrateWithIds().
 *
 * Anything outside that shape — extra WHERE clauses, other joins, other sort fields, GROUP BY —
 * is left to MySQL untouched. A wrong guess here would silently change what a merchant's home
 * page shows, so the recogniser is deliberately strict.
 *
 * Two id sources (fastmagento/serving/widget_ids_source):
 *  - "sql" (default): the ids come from ONE index-only query built from the collection's own
 *    SELECT. Byte-identical to the native page, including MySQL's undefined order among products
 *    with equal price or position — which no other source can reproduce.
 *  - "index": the ids come from Magento's search index; ties are broken by entity id, so a
 *    slider sorted by price may show two equally-priced products in the other order than MySQL
 *    would. Zero MySQL for the widget.
 */
class CategoryWidgetServer
{
    public const XML_PATH_ENABLED = 'fastmagento/serving/serve_widget_collections';
    public const XML_PATH_IDS_SOURCE = 'fastmagento/serving/widget_ids_source';
    public const IDS_FROM_SQL = 'sql';
    public const IDS_FROM_INDEX = 'index';

    private const MAX_SIZE = 500;

    /** Join aliases the standard category collection uses; any other alias disqualifies. */
    private const KNOWN_JOINS = [
        'e', 'cat_index', 'review_summary', 'product_website', 'at_visibility', 'at_visibility_default',
        'stock_status_index', 'price_index', 'at_price', 'at_price_default',
    ];

    private ?object $client = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly IndexNameResolver $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpContext $httpContext,
        private readonly OpenSearchPdpFetcher $fetcher,
        private readonly ListingHydrator $hydrator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function idsSource(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_IDS_SOURCE, ScopeInterface::SCOPE_STORE) === self::IDS_FROM_INDEX
            ? self::IDS_FROM_INDEX
            : self::IDS_FROM_SQL;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            && $this->hydrator->isEnabled();
    }

    /**
     * TRUE when the collection was filled from the indexes; FALSE = not our shape (or the index
     * could not answer), run the native load.
     */
    public function serve(ProductCollection $collection): bool
    {
        try {
            $shape = $this->recognise($collection);
            if ($shape === null) {
                return false;
            }
            if ($this->idsSource() !== self::IDS_FROM_INDEX) {
                // Parity mode (default): the id list — membership, filters, sort AND MySQL's own
                // order among equal sort keys — from one index-only query; everything about the
                // products from the documents.
                $ids = $this->hydrator->idsViaSql($collection);
                if (!$ids) {
                    // The collection's own query says "no products": that IS the answer, so the
                    // native load (the same query with e.* and the attribute passes) is not run.
                    return true;
                }
                return $this->hydrator->hydrateWithIds($collection, $ids, false);
            }
            $ids = $this->resolveIds($shape);
            if ($ids === null) {
                return false;
            }
            if ($shape['post_filter']) {
                // Conditions the search index cannot express are applied on the documents (which
                // hydration fetches anyway), BEFORE paging, exactly as the WHERE would have been.
                $ids = $this->postFilter($ids, $shape);
                $ids = array_slice($ids, $shape['offset'], $shape['limit']);
            }
            if (!$ids) {
                return false;   // let the native path produce the empty collection and its messaging
            }
            // Stock was already applied in postFilter() when it was owed; nothing left for the hydrator.
            return $this->hydrator->hydrateWithIds($collection, $ids, false);
        } catch (\Throwable $e) {
            $this->logger->debug('[FastMagento] category widget not served from the index: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return array{category_id:int, visibility:int[], direct_only:bool, in_stock_only:bool, sort:string, dir:string, limit:int, offset:int}|null
     */
    private function recognise(ProductCollection $collection): ?array
    {
        $select = $collection->getSelect();
        if ($select->getPart(Select::GROUP) || $select->getPart(Select::HAVING) || $select->getPart(Select::UNION)) {
            return null;
        }
        $from = (array) $select->getPart(Select::FROM);
        if (!isset($from['cat_index']) || !isset($from['e'])) {
            return null;
        }
        foreach (array_keys($from) as $alias) {
            if (!in_array((string) $alias, self::KNOWN_JOINS, true)) {
                return null;
            }
        }
        $cond = (string) ($from['cat_index']['joinCondition'] ?? '');
        if (!preg_match('/cat_index\.category_id\s*=\s*\'?(\d+)\'?/', $cond, $m)) {
            return null;
        }
        $categoryId = (int) $m[1];
        $visibility = [];
        if (preg_match('/cat_index\.visibility\s+IN\s*\(([\d,\s]+)\)/i', $cond, $m)) {
            $visibility = array_values(array_filter(array_map('intval', explode(',', $m[1]))));
        }
        $directOnly = (bool) preg_match('/cat_index\.is_parent\s*=\s*\'?1\'?/', $cond);

        $inStockOnly = false;
        $priceMin = null;
        $priceMax = null;
        foreach ((array) $select->getPart(Select::WHERE) as $clause) {
            $clause = (string) $clause;
            if (preg_match('/stock_status_index\.stock_status\s*=\s*\'?1\'?/', $clause)) {
                $inStockOnly = true;
            } elseif (preg_match('/at_visibility|product_website\.website_id/', $clause)) {
                continue;   // visibility and website are already in the join / the index
            } elseif (preg_match('/^\(*\s*(AND\s+)?\(*at_price\.value\s*(>=|<=)\s*\'?([\d.]+)\'?\)*$/', $clause, $pm)) {
                // Widget "price from / to" on the base price attribute, applied on the documents.
                if ($pm[2] === '>=') {
                    $priceMin = (float) $pm[3];
                } else {
                    $priceMax = (float) $pm[3];
                }
            } else {
                return null;   // a predicate the index cannot mirror
            }
        }
        if (!$inStockOnly && isset($from['stock_status_index'])) {
            $inStockOnly = (bool) preg_match(
                '/stock_status\s*=\s*\'?1\'?/',
                (string) ($from['stock_status_index']['joinCondition'] ?? '')
            );
        }

        $orders = (array) $select->getPart(Select::ORDER);
        $sort = 'entity';
        $dir = 'asc';
        if (count($orders) > 1) {
            return null;
        }
        if ($orders) {
            [$field, $direction] = is_array($orders[0]) ? $orders[0] : [(string) $orders[0], 'ASC'];
            $field = str_replace('`', '', (string) $field);
            $dir = strtoupper((string) $direction) === 'DESC' ? 'desc' : 'asc';
            if (preg_match('/^cat_index\.position$/', $field)) {
                $sort = 'position';
            } elseif (preg_match('/^price_index\.min_price$/', $field)) {
                $sort = 'price';
            } elseif (preg_match('/^e\.entity_id$/', $field)) {
                $sort = 'entity';
            } else {
                return null;
            }
        }

        // The EAV collection applies its page size inside _loadEntities(), i.e. after this
        // point, so read it from the collection when the select does not carry it yet.
        $limit = (int) $select->getPart(Select::LIMIT_COUNT);
        $offset = (int) $select->getPart(Select::LIMIT_OFFSET);
        if ($limit <= 0 && (int) $collection->getPageSize() > 0) {
            $limit = (int) $collection->getPageSize();
            $offset = (max(1, (int) $collection->getCurPage()) - 1) * $limit;
        }
        if ($limit <= 0 || $limit > self::MAX_SIZE) {
            return null;   // an unbounded widget is not a widget; leave it to MySQL
        }
        return [
            'category_id' => $categoryId,
            'visibility' => $visibility,
            'direct_only' => $directOnly,
            'in_stock_only' => $inStockOnly,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'post_filter' => $directOnly || $inStockOnly || $priceMin !== null || $priceMax !== null,
            'sort' => $sort,
            'dir' => $dir,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @param array<string, mixed> $shape
     * @return int[]|null
     */
    private function resolveIds(array $shape): ?array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $websiteId = (int) $store->getWebsiteId();
        $groupId = (int) ($this->httpContext->getValue(CustomerContext::CONTEXT_GROUP) ?? 0);

        $filter = [['term' => ['category_ids' => $shape['category_id']]]];
        if ($shape['visibility']) {
            $filter[] = ['terms' => ['visibility' => $shape['visibility']]];
        }
        switch ($shape['sort']) {
            case 'position':
                $sort = [['position_category_' . $shape['category_id'] => $shape['dir']], ['_id' => 'asc']];
                break;
            case 'price':
                $sort = [['price_' . $groupId . '_' . $websiteId => $shape['dir']], ['_id' => 'asc']];
                break;
            default:
                // No ORDER BY: MySQL returns the category index in primary-key order, which is
                // (category_id, product_id) — i.e. product id ascending.
                $sort = [['_id' => 'asc']];
        }
        // With a document-side filter still to come, fetch the whole ordered candidate list (capped)
        // and page after filtering; otherwise let the engine page.
        $from = $shape['post_filter'] ? 0 : $shape['offset'];
        $size = $shape['post_filter'] ? self::MAX_SIZE : $shape['limit'];

        $client = $this->getClient();
        if (!$client) {
            return null;
        }
        $resp = $client->search([
            // The alias Magento's own search adapter reads (…_product_<store>), never a versioned name.
            'index' => $this->indexNameResolver->getIndexNameForAlias(
                $storeId,
                $this->indexNameResolver->getIndexMapping('catalogsearch_fulltext')
            ),
            'body' => [
                'from' => $from,
                'size' => $size,
                '_source' => false,
                'query' => ['bool' => ['filter' => $filter]],
                'sort' => $sort,
            ],
        ]);
        $ids = [];
        foreach ($resp['hits']['hits'] ?? [] as $hit) {
            $ids[] = (int) $hit['_id'];
        }
        return $ids;
    }

    /**
     * The conditions Magento's search index cannot express, applied on the FastMagento documents
     * in engine order (the documents are memoised per request, so hydration will not fetch again):
     *  - is_parent=1: assigned to the category itself, not inherited through an anchor parent
     *    (category_ids holds the direct assignments);
     *  - stock_status=1: is_in_stock;
     *  - base-price range: the price attribute.
     *
     * @param int[] $ids
     * @param array<string, mixed> $shape
     * @return int[]
     */
    private function postFilter(array $ids, array $shape): array
    {
        $docs = $this->fetcher->fetchByIds($ids);
        $out = [];
        foreach ($ids as $id) {
            $doc = $docs[$id] ?? null;
            if ($doc === null) {
                continue;
            }
            if ($shape['direct_only']
                && !in_array($shape['category_id'], array_map('intval', (array) ($doc['category_ids'] ?? [])), true)) {
                continue;
            }
            if ($shape['in_stock_only'] && empty($doc['is_in_stock'])) {
                continue;
            }
            if ($shape['price_min'] !== null || $shape['price_max'] !== null) {
                if (!isset($doc['price']) || $doc['price'] === '') {
                    continue;   // NULL price never satisfies a range in SQL either
                }
                $price = (float) $doc['price'];
                if (($shape['price_min'] !== null && $price < $shape['price_min'])
                    || ($shape['price_max'] !== null && $price > $shape['price_max'])) {
                    continue;
                }
            }
            $out[] = $id;
        }
        return $out;
    }

    private function getClient(): ?object
    {
        if ($this->client === null) {
            $this->client = $this->clientResolver
                ->create($this->engineResolver->getCurrentSearchEngine())
                ->getOpenSearchClient();
        }
        return $this->client;
    }
}
