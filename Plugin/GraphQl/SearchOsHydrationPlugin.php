<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\GraphQl;

use Magento\Customer\Model\Group as CustomerGroup;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Search\Response\Aggregation;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Model\GraphQl\FacetHolder;
use ParkkTech\FastMagento\Model\GraphQl\FastMagentoAggregation;
use ParkkTech\FastMagento\Model\Search\InstantSearch;

/**
 * Route GraphQL's `products(search:)` id-resolution step through FastMagento's own OS-direct
 * `Model\Search\InstantSearch` instead of core's `Magento\Search\Api\SearchInterface` (the
 * MatchQuery adapter that resolves ~45 per-searchable-attribute `eav_attribute` loadByCode
 * queries every request - the field-mapper needs each searchable attribute's metadata to build
 * the ES/OS query). The storefront instant-search bar already goes straight to OS via
 * InstantSearch (11 SQL, 0 eav_attribute); this makes GraphQL search share that same path.
 *
 * Only the ID-RESOLUTION step changes. Product hydration is unaffected - it's already handled
 * by {@see ProductCollectionOsHydrationPlugin} on `Collection::load()`, downstream of this
 * plugin, in `ProductSearch::getList()`.
 *
 * Both the plain `graphql_product_search` request and `graphql_product_search_with_aggregation`
 * (the query selects `aggregations`/`filters`) are served. For the latter, InstantSearch is
 * called with facets on and the formatted facets are carried on the SearchResult as a
 * {@see FastMagentoAggregation}; {@see \ParkkTech\FastMagento\Plugin\GraphQl\AggregationsOsHydrationPlugin}
 * detects that marker and builds the GraphQL aggregation array directly from it, bypassing
 * core's layerBuilder (which fatals on any bucket whose attribute isn't a filterable EAV
 * attribute - exactly what native GraphQL aggregations crash on for this catalogue). If
 * InstantSearch produces no facets at all, the whole query falls back to native rather than
 * return an aggregation-less result for a query that asked for one.
 *
 * SCOPE (Phase 1, deliberately conservative - correct over fast):
 *  - Sort: the DEFAULT relevance sort (`[relevance DESC, entity_id tiebreaker]`, the exact shape
 *    `SearchCriteriaBuilder::build()` produces when no explicit `sort:` arg is given), an explicit
 *    `sort:{price:ASC|DESC}`, or `sort:{name:ASC|DESC}` are fast-pathed (mapped to InstantSearch's
 *    OS sort override - see {@see mapSort()}). Price sort only maps for the guest/default
 *    customer group (group 0); any other group falls through to native rather than sort on the
 *    wrong per-group price field - see {@see resolveGuestPriceField()}. Anything else (position,
 *    a custom attribute, multiple sort fields) falls through to native.
 *  - Only `search_term` (+ optional single `category_id`) filters are handled; ANY other filter
 *    field on the search criteria (a custom-attribute `filter:{...}` alongside the search term)
 *    falls through to native rather than risk an AND/OR semantics mismatch between GraphQL's
 *    filter-group shape and InstantSearch's flat per-field terms filters.
 *
 * SAFETY: whole body wrapped in try/catch - ANY Throwable, or any of the above scope gates,
 * returns `$proceed($searchCriteria)` (100% native, byte-for-byte identical to stock Magento).
 */
class SearchOsHydrationPlugin
{
    private const XML_PATH_OS_SERVE = 'fastmagento/graphql/os_serve_search';

    private const REQUEST_NAME_SEARCH = 'graphql_product_search';
    private const REQUEST_NAME_WITH_AGGREGATION = 'graphql_product_search_with_aggregation';
    private const SUPPORTED_REQUEST_NAMES = [self::REQUEST_NAME_SEARCH, self::REQUEST_NAME_WITH_AGGREGATION];

    private const FIELD_SEARCH_TERM = 'search_term';
    private const FIELD_VISIBILITY = 'visibility';
    private const FIELD_CATEGORY_ID = 'category_id';
    /** Added by SearchCriteriaBuilder::preparePriceAggregation() for aggregation requests; a
     *  hint for native's price-range-bucketing algorithm, not a product filter - safe to ignore. */
    private const FIELD_PRICE_DYNAMIC_ALGORITHM = 'price_dynamic_algorithm';

    public function __construct(
        private readonly AppState $appState,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly InstantSearch $instantSearch,
        private readonly SearchResultFactory $searchResultFactory,
        private readonly DocumentFactory $documentFactory,
        private readonly WriteLog $writeLog,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly FacetHolder $facetHolder
    ) {
    }

    /**
     * @param SearchInterface $subject
     * @param callable $proceed
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSearch(SearchInterface $subject, callable $proceed, SearchCriteriaInterface $searchCriteria)
    {
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_GRAPHQL
                || !$this->scopeConfig->isSetFlag(self::XML_PATH_OS_SERVE, ScopeInterface::SCOPE_STORE)
                || !in_array($searchCriteria->getRequestName(), self::SUPPORTED_REQUEST_NAMES, true)
            ) {
                return $proceed($searchCriteria);
            }

            $term = $this->extractSearchTerm($searchCriteria);
            if ($term === null || $term === '') {
                // Not a text search (e.g. a plain filtered listing sharing this request name) -
                // nothing for InstantSearch to do; native handles category/attribute browsing.
                return $proceed($searchCriteria);
            }

            $sortMap = $this->mapSort($searchCriteria->getSortOrders());
            if (!$sortMap['mappable']) {
                return $proceed($searchCriteria);
            }

            $filters = $this->extractFilters($searchCriteria);
            if ($filters === null) {
                return $proceed($searchCriteria);
            }

            $pageSize = (int) $searchCriteria->getPageSize();
            // SearchCriteria's currentPage is 0-based (see Elasticsearch\...\SearchCriteriaResolver
            // ::resolve(), which stores $args['currentPage'] - 1); InstantSearch's $page is 1-based.
            $currentPage = ((int) $searchCriteria->getCurrentPage()) + 1;
            if ($pageSize <= 0) {
                return $proceed($searchCriteria);
            }

            $withAggregation = $searchCriteria->getRequestName() === self::REQUEST_NAME_WITH_AGGREGATION;

            $result = $this->instantSearch->search(
                $term,
                $currentPage,
                $pageSize,
                $filters,
                $withAggregation,
                $sortMap['override'],
                $withAggregation
            );

            if ($withAggregation && empty($result['facets'])) {
                // The query asked for aggregations but InstantSearch produced none (e.g. no
                // facet attributes configured) - native builds a correct, if slower, aggregation
                // from the raw engine response instead of us returning an empty one.
                return $proceed($searchCriteria);
            }

            $items = [];
            foreach ($result['products'] as $product) {
                if (empty($product['id'])) {
                    continue;
                }
                $document = $this->documentFactory->create();
                $document->setId((int) $product['id']);
                $items[] = $document;
            }

            /** @var SearchResultInterface $searchResult */
            $searchResult = $this->searchResultFactory->create();
            $searchResult->setItems($items);
            $searchResult->setTotalCount((int) $result['total']);
            if ($withAggregation) {
                // The object set here does not reliably survive to the Aggregations resolver
                // (see FacetHolder's docblock), so stash the facets in the request-scoped holder
                // too - that is what AggregationsOsHydrationPlugin actually reads. Still setting
                // FastMagentoAggregation here as well: it is the correct/intended shape for
                // anything that reads getSearchAggregation() directly off THIS return value
                // (e.g. Query\Search::getResult()'s own 'searchAggregation' key).
                $this->facetHolder->setFacets($result['facets']);
                $searchResult->setAggregations(new FastMagentoAggregation($result['facets']));
            } else {
                $searchResult->setAggregations(new Aggregation([]));
            }
            $searchResult->setSearchCriteria($searchCriteria);

            return $searchResult;
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                'GraphQL OS search serve failed; native fallback: ' . $e->getMessage()
            );
            return $proceed($searchCriteria);
        }
    }

    /**
     * Pull the `search_term` filter's value off the search criteria (set by
     * `SearchCriteriaBuilder::addFilter($sc, 'search_term', $args['search'])`).
     */
    private function extractSearchTerm(SearchCriteriaInterface $searchCriteria): ?string
    {
        foreach ($searchCriteria->getFilterGroups() as $group) {
            foreach ($group->getFilters() as $filter) {
                if ($filter->getField() === self::FIELD_SEARCH_TERM) {
                    $value = $filter->getValue();
                    return is_scalar($value) ? trim((string) $value) : null;
                }
            }
        }
        return null;
    }

    /**
     * Is this exactly the default `[relevance DESC, entity_id tiebreaker]` sort
     * `SearchCriteriaBuilder::build()`/`addEntityIdSort()` produce when no explicit `sort:` arg
     * is given?
     *
     * @param SortOrder[]|null $sorts
     */
    private function isDefaultRelevanceSort(?array $sorts): bool
    {
        if (!is_array($sorts) || count($sorts) !== 2) {
            return false;
        }
        [$primary, $tiebreaker] = array_values($sorts);
        return $primary instanceof SortOrder
            && $primary->getField() === 'relevance'
            && $primary->getDirection() === SortOrder::SORT_DESC
            && $tiebreaker instanceof SortOrder
            && $tiebreaker->getField() === 'entity_id';
    }

    /**
     * Map the search criteria's sort orders to an InstantSearch OS sort override, or say it
     * cannot be mapped (caller falls back to native).
     *
     * `SearchCriteriaBuilder::addEntityIdSort()` always appends an `entity_id` tiebreaker after
     * whatever the client requested, so a mappable request is always exactly two SortOrders:
     * the requested field, then the entity_id tiebreaker. Anything else (a multi-field sort, a
     * tiebreaker that isn't entity_id, or a field this method does not recognise) is unmappable.
     *
     * @param SortOrder[]|null $sorts
     * @return array{mappable: bool, override: array|null} override is null both for "unmappable"
     *         (mappable false) and for "default relevance" (mappable true, meaning: pass null
     *         through to InstantSearch::search() and let it use its own buildSort() ranking).
     */
    private function mapSort(?array $sorts): array
    {
        if ($this->isDefaultRelevanceSort($sorts)) {
            return ['mappable' => true, 'override' => null];
        }
        if (!is_array($sorts) || count($sorts) !== 2) {
            return ['mappable' => false, 'override' => null];
        }
        [$primary, $tiebreaker] = array_values($sorts);
        if (!$primary instanceof SortOrder
            || !$tiebreaker instanceof SortOrder
            || $tiebreaker->getField() !== 'entity_id'
        ) {
            return ['mappable' => false, 'override' => null];
        }

        $direction = $primary->getDirection() === SortOrder::SORT_DESC ? 'desc' : 'asc';

        if ($primary->getField() === 'name') {
            return ['mappable' => true, 'override' => [['name.sort_name' => ['order' => $direction]]]];
        }

        if ($primary->getField() === 'price') {
            $priceField = $this->resolveGuestPriceField();
            if ($priceField === null) {
                // Non-default customer group, or website could not be resolved - do not guess
                // the wrong per-group price field; native is the safe choice.
                return ['mappable' => false, 'override' => null];
            }
            return ['mappable' => true, 'override' => [[$priceField => ['order' => $direction]]]];
        }

        // position, a custom attribute, or anything else not indexed under a predictable name.
        return ['mappable' => false, 'override' => null];
    }

    /**
     * The indexed price sort field (`price_<customerGroup>_<website>`) for the CURRENT
     * customer, but ONLY when that customer is the guest/default group (0). GraphQL requests
     * have no storefront session by default, so this deliberately does not attempt to resolve
     * any other customer group here - returning null sends the query through the native path,
     * which resolves group-specific pricing correctly, rather than risk sorting (and, via
     * total_count, subtly filtering) by the wrong group's price.
     */
    private function resolveGuestPriceField(): ?string
    {
        try {
            $groupId = (int) $this->customerSession->getCustomerGroupId();
            if ($groupId !== CustomerGroup::NOT_LOGGED_IN_ID) {
                return null;
            }
            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
            if ($websiteId <= 0) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return 'price_' . $groupId . '_' . $websiteId;
    }

    /**
     * Map the search criteria's filter groups to InstantSearch's `attributeCode => values[]`
     * shape. Returns null (-> native fallback) on any filter field/condition this Phase-1
     * mapping doesn't confidently support - see class docblock for why this is deliberately
     * narrow (search_term + optional single category_id only).
     *
     * @return array<string, string[]>|null
     */
    private function extractFilters(SearchCriteriaInterface $searchCriteria): ?array
    {
        $filters = [];
        foreach ($searchCriteria->getFilterGroups() as $group) {
            foreach ($group->getFilters() as $filter) {
                $field = (string) $filter->getField();
                if ($field === self::FIELD_SEARCH_TERM
                    || $field === self::FIELD_VISIBILITY
                    || $field === self::FIELD_PRICE_DYNAMIC_ALGORITHM
                ) {
                    // search_term is the query itself; visibility is already implicit in the
                    // native fulltext index InstantSearch queries (it only ever indexes
                    // search-visible, enabled products for the store); price_dynamic_algorithm
                    // is a native-only bucketing hint (see the constant's docblock).
                    continue;
                }
                if ($field !== self::FIELD_CATEGORY_ID) {
                    // Any other filter field (a custom-attribute filter alongside the search
                    // term) is out of Phase-1 scope - native is the safe choice.
                    return null;
                }
                $conditionType = $filter->getConditionType();
                if ($conditionType !== null && !in_array($conditionType, ['eq', 'in'], true)) {
                    return null;
                }
                $value = $filter->getValue();
                foreach ((is_array($value) ? $value : [$value]) as $v) {
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $filters['category'][] = (string) $v;
                }
            }
        }
        return $filters;
    }
}
