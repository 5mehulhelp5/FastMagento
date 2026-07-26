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
 * SCOPE (Phase 1, deliberately conservative - correct over fast):
 *  - Only the plain `graphql_product_search` request name is fast-pathed. The
 *    `graphql_product_search_with_aggregation` variant (the query selects `aggregations`/
 *    `filters`) falls straight through to native - mapping InstantSearch's facet buckets to
 *    `AggregationInterface` correctly is a separate piece of work; a wrong facet is worse than
 *    a slower one.
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

    /** The `_with_aggregation` request name is out of Phase-1 scope; see class docblock. */
    private const SUPPORTED_REQUEST_NAMES = ['graphql_product_search'];

    private const FIELD_SEARCH_TERM = 'search_term';
    private const FIELD_VISIBILITY = 'visibility';
    private const FIELD_CATEGORY_ID = 'category_id';

    public function __construct(
        private readonly AppState $appState,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly InstantSearch $instantSearch,
        private readonly SearchResultFactory $searchResultFactory,
        private readonly DocumentFactory $documentFactory,
        private readonly WriteLog $writeLog,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager
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

            $result = $this->instantSearch->search(
                $term,
                $currentPage,
                $pageSize,
                $filters,
                false,
                $sortMap['override']
            );

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
            // No facets computed in this phase (see class docblock); an empty aggregation is
            // safe because a plain `graphql_product_search` request never selects
            // aggregations/filters (SearchCriteriaBuilder::build() would have used the
            // `_with_aggregation` request name instead, which we don't fast-path).
            $searchResult->setAggregations(new Aggregation([]));
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
                if ($field === self::FIELD_SEARCH_TERM || $field === self::FIELD_VISIBILITY) {
                    // search_term is the query itself; visibility is already implicit in the
                    // native fulltext index InstantSearch queries (it only ever indexes
                    // search-visible, enabled products for the store).
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
