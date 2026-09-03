<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Group as CustomerGroup;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * OpenSearch-backed instant-search / autocomplete query service.
 *
 * Relevance + facets come from Magento's native fulltext index (alias
 * `<prefix>_product_<storeId>`, which is properly analyzed and only holds search-visible
 * products); display fields (name, price, image, url) are hydrated from FastMagento's own
 * `magento2_products` _source in a single mget. This gives Algolia-style as-you-type
 * results without a bespoke mapping or reindex, and every call degrades to an empty result
 * set (never throws) so the storefront input can't break.
 */
class InstantSearch
{
    /** Sentinel used as the numeric upper bound of the open-ended top price range bucket. */
    private const PRICE_RANGE_OPEN_END = 999999;

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly WriteLog $writeLog,
        private readonly RelevanceConfig $relevanceConfig,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerSession $customerSession,
        private readonly QueryDecoratorInterface $queryDecorator,
        private readonly ExplorationWindowInterface $exploration
    ) {
    }

    /**
     * @param string $query
     * @param int $page 1-based
     * @param int $pageSize
     * @param array<string, string[]> $filters attributeCode => selected values (native field terms)
     * @param bool $withFacets aggregate category + price + configured attribute facets
     * @param array<int, array<string, array<string, string>>>|null $sortOverride explicit OS sort
     *        clauses (e.g. [['price_0_1' => ['order' => 'asc']]]) used INSTEAD of buildSort()'s
     *        relevance ranking. Null (default) keeps today's behaviour unchanged, so callers that
     *        never pass it - the storefront Suggest/Instant controllers - are unaffected. A
     *        deterministic `_id` tiebreaker is appended automatically so paging stays stable
     *        regardless of what the caller supplies.
     * @param bool $includePriceFacet add a price-range facet alongside category/attribute facets.
     *        Default false so the storefront Suggest/Instant controllers (which never pass it)
     *        are unaffected - only the GraphQL aggregation path opts in explicitly.
     * @return array{query:string,total:int,page:int,page_size:int,products:array,facets:array}
     */
    public function search(
        string $query,
        int $page = 1,
        int $pageSize = 8,
        array $filters = [],
        bool $withFacets = false,
        ?array $sortOverride = null,
        bool $includePriceFacet = false
    ): array {
        $query = trim($query);
        $page = max(1, $page);
        $pageSize = max(1, min(48, $pageSize));
        $empty = [
            'query' => $query,
            'total' => 0,
            'page' => $page,
            'page_size' => $pageSize,
            'products' => [],
            'facets' => [],
        ];
        if ($query === '') {
            return $empty;
        }

        try {
            $client = $this->getClient();

            $storeId = (int) $this->storeManager->getStore()->getId();

            // The exploration slot needs the ranking below the fold to choose from, so an
            // eligible request fetches the whole window and slices its page out afterwards — the
            // same global-permutation contract the native path uses, so both surfaces paginate
            // consistently. Never on an explicit sort: a shopper who ordered by price gets price
            // order, exactly. See ExplorationSlot for the policy.
            $from = ($page - 1) * $pageSize;
            $explore = $sortOverride === null
                && $this->exploration->isActive($storeId)
                && $from < $this->exploration->windowSize($pageSize);

            $body = [
                'from' => $explore ? 0 : $from,
                'size' => $explore ? max($from + $pageSize, $this->exploration->windowSize($pageSize)) : $pageSize,
                'query' => $this->buildQuery($query, $filters),
                '_source' => ['name', 'sku'],
                'track_total_hits' => true,
            ];
            if ($sortOverride !== null) {
                // Explicit sort (e.g. GraphQL sort:{price:ASC}); always append a stable tiebreaker
                // so paging is deterministic even when the override omits one. `entity_id` is not
                // a mapped field in this index (the doc id lives in `_id`), so tiebreak on `_id`
                // itself - always sortable, always unique.
                $sort = array_merge($sortOverride, [['_id' => ['order' => 'asc']]]);
            } else {
                $sort = $this->buildSort();
            }
            if ($sort) {
                $body['sort'] = $sort;
            }
            if ($withFacets) {
                $body['aggs'] = $this->buildAggregations($includePriceFacet);
            }

            $index = $this->getSearchIndex($storeId);
            try {
                $response = $client->getOpenSearchClient()->search(['index' => $index, 'body' => $body]);
            } catch (\Throwable $primaryError) {
                // The rich relevance query can exceed OpenSearch's boolean max_clause_count on a
                // long or synonym-heavy input (too_many_nested_clauses). Retry once with a compact
                // best-fields query that is always within the limit, so a valid search returns its
                // best matches instead of failing to the empty set.
                $this->writeLog->writeErrorLog(
                    '[FastMagento] instant search primary query failed, retrying simplified: ' . $primaryError->getMessage()
                );
                $body['query'] = $this->buildFallbackQuery($query, $filters);
                $response = $client->getOpenSearchClient()->search(['index' => $index, 'body' => $body]);
            }

            $hits = $response['hits']['hits'] ?? [];
            $ids = array_map(static fn ($h) => (int) $h['_id'], $hits);

            if ($explore) {
                $ids = array_slice(
                    $this->exploration->permute($ids, $pageSize, $storeId),
                    $from,
                    $pageSize
                );
            }

            return [
                'query' => $query,
                'total' => (int) ($response['hits']['total']['value'] ?? count($ids)),
                'page' => $page,
                'page_size' => $pageSize,
                'products' => $this->hydrateProducts($client, $ids, $query),
                'facets' => $withFacets ? $this->formatFacets($response['aggregations'] ?? []) : [],
            ];
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] instant search failed: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Relevance-debug view: run the real search query and return the ranked hits WITH their
     * `_score`, straight from the native fulltext index (no hydration). Intended for the offline
     * relevance harness (docs/tools/search-relevance.php) so ranking changes are measured against
     * golden queries, not guessed. Read-only; degrades to an empty list, never throws.
     *
     * @param array<string, string[]> $filters
     * @return array{query:string,total:int,operator:string,hits:array<int,array{id:int,score:float,name:string,sku:string}>}
     */
    public function debugSearch(string $query, int $size = 10, array $filters = []): array
    {
        $query = trim($query);
        $out = ['query' => $query, 'total' => 0, 'operator' => $this->relevanceConfig->getSearchOperator(), 'hits' => []];
        if ($query === '') {
            return $out;
        }
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $response = $this->getClient()->getOpenSearchClient()->search([
                'index' => $this->getSearchIndex($storeId),
                'body' => [
                    'from' => 0,
                    'size' => max(1, min(50, $size)),
                    'query' => $this->buildQuery($query, $filters),
                    '_source' => ['name', 'sku'],
                    'track_total_hits' => true,
                ],
            ]);
            $out['total'] = (int) ($response['hits']['total']['value'] ?? 0);
            foreach ($response['hits']['hits'] ?? [] as $hit) {
                $out['hits'][] = [
                    'id' => (int) $hit['_id'],
                    'score' => (float) ($hit['_score'] ?? 0),
                    'name' => (string) ($hit['_source']['name'] ?? ''),
                    'sku' => (string) ($hit['_source']['sku'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] debugSearch failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * @param array<string, string[]> $filters
     * @return array<string, mixed>
     */
    private function buildQuery(string $query, array $filters): array
    {
        $fields = $this->relevanceConfig->getBoostedFields();
        $expanded = $this->expandQuery($query);
        $operator = $this->operatorParams();   // any/all/most per-clause term-coverage rule

        $phraseBoost = $this->relevanceConfig->getPhraseMatchBoost();
        $typo = $this->relevanceConfig->isTypoToleranceEnabled();

        // Candidate set = the cleaned query PLUS every synonym variant, treated as EQUIVALENT and
        // scored identically. Because synonyms are symmetric ("frontend" "front end" produce
        // the same {frontend, front end} set), two queries that mean the same thing yield the same
        // results in the same order — no synonym demotion. Each candidate gets the full scoring
        // treatment below (as-you-type prefix + full-field match + phrase + all-terms).
        $candidates = array_values(array_unique(array_filter(
            array_merge([$expanded['clean']], $expanded['variants']),
            static fn ($c) => $c !== ''
        )));

        $should = [];
        foreach ($candidates as $candidate) {
            $multiWord = strpos($candidate, ' ') !== false;

            // As-you-type prefix match (last term is a prefix); field boosts from admin weights.
            $prefix = ['query' => $candidate, 'type' => 'bool_prefix', 'fields' => $fields] + $operator;
            if ($typo) {
                $prefix['fuzziness'] = 'AUTO';
            }
            $should[] = ['multi_match' => $prefix];

            // Full-word best-field match (the equivalence match — full strength, no demotion).
            $should[] = ['multi_match' =>
                ['query' => $candidate, 'type' => 'best_fields', 'fields' => $fields, 'tie_breaker' => 0.3]
                + $operator
            ];

            if ($multiWord) {
                // Exact/phrase boost: a contiguous-phrase hit outranks scattered-token hits.
                if ($phraseBoost > 0) {
                    $should[] = ['multi_match' => [
                        'query' => $candidate, 'type' => 'phrase', 'fields' => $fields,
                        'slop' => 2, 'boost' => $phraseBoost, 'tie_breaker' => 0.3,
                    ]];
                }
                // All-terms precision boost: a product matching EVERY term (cross_fields, terms may
                // be spread across name + keywords + description) ranks well above a single-term hit.
                $should[] = ['multi_match' => [
                    'query' => $candidate, 'type' => 'cross_fields', 'operator' => 'and',
                    'fields' => $fields, 'boost' => max(2.0, $phraseBoost * 0.75),
                ]];
            }
        }

        // Preserve the RAW multi-word phrase (before stop-word stripping) so a buyer phrase whose
        // middle word is a stop word survives — "side by side" must stay intact rather than
        // collapse to the cleaned "side side".
        $rawLower = trim((string) preg_replace('/\s+/', ' ', mb_strtolower($query)));
        if ($phraseBoost > 0 && strpos($rawLower, ' ') !== false && $rawLower !== $expanded['clean']) {
            $should[] = ['multi_match' => [
                'query' => $rawLower, 'type' => 'phrase', 'fields' => $fields,
                'slop' => 2, 'boost' => $phraseBoost, 'tie_breaker' => 0.3,
            ]];
        }

        $filterClauses = $this->buildFilterClauses($filters);

        $bool = ['should' => $should, 'minimum_should_match' => 1];
        if ($filterClauses) {
            $bool['filter'] = $filterClauses;
        }

        // Gently boost in-stock products above out-of-stock ones (keeps text relevance
        // primary, unlike a hard sort).
        if ($this->relevanceConfig->isBoostInStockEnabled()) {
            return $this->decorateQuery([
                'function_score' => [
                    'query' => ['bool' => $bool],
                    'functions' => [[
                        'filter' => ['term' => ['is_out_of_stock' => 0]],
                        'weight' => 1.6,
                    ]],
                    'boost_mode' => 'multiply',
                    'score_mode' => 'sum',
                ],
            ]);
        }
        return $this->decorateQuery(['bool' => $bool]);
    }

    /**
     * Hand the assembled query to the decoration seam — the last step before it is issued.
     *
     * Sits here, after every relevance clause is built, so a decorator only ever adds to a finished
     * ranking and never changes textual or business relevance. Core's default decorator returns
     * the identical array; any decorator is required to fail closed, and this wrapper guarantees
     * it: a throwing decorator costs nothing but its own effect.
     */
    private function decorateQuery(array $query): array
    {
        try {
            // Surface and target identifiers a decorator keys on: this is the storefront search
            // surface, ranking against Magento's native index (see the TARGET_NATIVE note below).
            return $this->queryDecorator->decorate(
                $query,
                'search',
                // TARGET_NATIVE, not the serving index. getSearchIndex() resolves
                // {prefix}_product_{storeId} — this grid RANKS against Magento's index and only
                // HYDRATES from FastMagento's. Gating with serving-index shares here would emit
                // `attributes.color = "Black"` against an index whose colour field is an integer
                // option id, matching nothing, silently.
                'native',
                (int) $this->storeManager->getStore()->getId()
            );
        } catch (\Throwable $e) {
            // A decorator is never allowed to cost a shopper their search results.
            return $query;
        }
    }

    /**
     * Turn the {field: [values]} filter set into OpenSearch `terms` filter clauses.
     *
     * @param array<string, string[]> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildFilterClauses(array $filters): array
    {
        $filterClauses = [];
        foreach ($filters as $field => $values) {
            $values = array_values(array_filter((array) $values, static fn ($v) => $v !== '' && $v !== null));
            if (!$values) {
                continue;
            }
            $filterClauses[] = ['terms' => [$this->filterField($field) => $values]];
        }
        return $filterClauses;
    }

    /**
     * A deliberately tiny relevance query used only when the full {@see buildQuery()} query is
     * rejected by OpenSearch for exceeding its boolean max_clause_count (the rich query multiplies
     * candidates x scoring-clauses x fields x terms, plus fuzzy/prefix term expansion, which a long
     * or synonym-heavy input can push past the 1024 default). One best-fields multi_match over the
     * cleaned query - no synonym candidates, no fuzzy/prefix/phrase/cross-fields - is a handful of
     * clauses that can never hit the limit, so the search still returns its best matches instead of
     * the empty set the error would otherwise produce. Filters are preserved.
     *
     * @param array<string, string[]> $filters
     */
    private function buildFallbackQuery(string $query, array $filters): array
    {
        $clean = $this->expandQuery($query)['clean'];
        if ($clean === '') {
            $clean = trim((string) preg_replace('/\s+/', ' ', mb_strtolower($query)));
        }

        $bool = [
            'should' => [[
                'multi_match' => [
                    'query' => $clean,
                    'type' => 'best_fields',
                    'fields' => $this->relevanceConfig->getBoostedFields(),
                    'tie_breaker' => 0.3,
                ],
            ]],
            'minimum_should_match' => 1,
        ];

        $filterClauses = $this->buildFilterClauses($filters);
        if ($filterClauses) {
            $bool['filter'] = $filterClauses;
        }

        return ['bool' => $bool];
    }

    /**
     * multi_match term-coverage params for the configured operator, applied per scoring clause:
     *  - any  OR (a single term is enough; today's behaviour)
     *  - most OR + minimum_should_match 75% (majority of terms must match)
     *  - all  AND (every term must match)
     *
     * @return array<string, string>
     */
    private function operatorParams(): array
    {
        switch ($this->relevanceConfig->getSearchOperator()) {
            case 'all':
                return ['operator' => 'and'];
            case 'most':
                return ['operator' => 'or', 'minimum_should_match' => '75%'];
            default:
                return ['operator' => 'or'];
        }
    }

    /**
     * Custom-ranking tie-breaker: text relevance first, then the configured numeric
     * attribute (Algolia's custom ranking after textual relevance).
     *
     * @return array<int, mixed>
     */
    private function buildSort(): array
    {
        $attribute = $this->relevanceConfig->getCustomRankingAttribute();
        if ($attribute === '') {
            return [];
        }
        return [
            '_score' => 'desc',
            [$attribute => ['order' => $this->relevanceConfig->getCustomRankingDirection(), 'missing' => '_last', 'unmapped_type' => 'float']],
        ];
    }

    /**
     * Strip stop words and build synonym variants via PHRASE-level substitution (capped).
     *
     * Substitution works on the whole raw query at the word-boundary level, so a group term
     * may be multi-word and may span/split tokens — this is what lets "frontend" "front end",
     * "side by side" "sxs" / "utv", and "a-arm" "control arm" all work (a token-level swap
     * cannot join or split words). Each produced variant is itself stop-word-stripped so it
     * stays clean under the AND/most operator. Substitution runs against the raw (not
     * stop-word-stripped) query so multi-word phrases like "side by side" survive intact.
     *
     * @return array{clean:string, variants:string[]}
     */
    private function expandQuery(string $query): array
    {
        $stop = $this->relevanceConfig->getStopwords();
        // Collapse internal whitespace so a multi-word synonym ("side by side") still matches a
        // query typed with stray double spaces.
        $lower = trim((string) preg_replace('/\s+/', ' ', mb_strtolower($query)));
        $stripStop = static function (string $phrase) use ($stop): string {
            return implode(' ', array_filter(
                preg_split('/\s+/', $phrase) ?: [],
                static fn ($t) => $t !== '' && !in_array($t, $stop, true)
            ));
        };

        $clean = $stripStop($lower) ?: $lower;   // don't blank an all-stopword query

        $variants = [];
        foreach ($this->relevanceConfig->getSynonymGroups() as $group) {
            foreach ($group as $term) {
                if ($term === '') {
                    continue;
                }
                $pattern = '/\b' . preg_quote($term, '/') . '\b/u';
                if (!preg_match($pattern, $lower)) {
                    continue;
                }
                foreach ($group as $syn) {
                    if ($syn === $term) {
                        continue;
                    }
                    // Callback replacement so a synonym containing $ / \1 is inserted literally,
                    // not interpreted as a preg backreference.
                    $variant = $stripStop(trim((string) preg_replace_callback(
                        $pattern,
                        static fn () => $syn,
                        $lower
                    )));
                    if ($variant !== '' && $variant !== $clean && !in_array($variant, $variants, true)) {
                        $variants[] = $variant;
                    }
                }
            }
        }
        // Cap synonym variants: each becomes a full set of scoring clauses in buildQuery(), so this
        // directly bounds the candidate multiplier on the OpenSearch boolean clause count.
        $variants = array_slice($variants, 0, 5);

        return ['clean' => $clean, 'variants' => $variants];
    }

    /**
     * Native fulltext field name for a facet/filter attribute. Category filter maps to
     * category_ids; everything else uses the attribute code as-is (Magento maps select /
     * filterable attributes under their code).
     */
    private function filterField(string $code): string
    {
        return $code === 'category' ? 'category_ids' : $code;
    }

    /**
     * @param bool $includePriceFacet see {@see search()}'s $includePriceFacet param.
     * @return array<string, mixed>
     */
    private function buildAggregations(bool $includePriceFacet = false): array
    {
        $aggs = [
            'category' => ['terms' => ['field' => 'category_ids', 'size' => 15]],
        ];
        foreach ($this->relevanceConfig->getFacetAttributes() as $code) {
            // Filterable select/multiselect attributes are indexed as option ids on {code}, with
            // the human label on {code}_value. Pull one hit per bucket carrying BOTH (they are
            // parallel arrays) so the option label comes straight from OpenSearch — no DB/EAV.
            $aggs[$code] = [
                'terms' => ['field' => $code, 'size' => 15],
                'aggs' => [
                    'label' => ['top_hits' => ['size' => 1, '_source' => [$code, $code . '_value']]],
                ],
            ];
        }
        if ($includePriceFacet) {
            $priceField = $this->resolveGuestPriceField();
            if ($priceField !== null) {
                // Static round-number breakpoints rather than a stats-then-range two-pass query:
                // one OS round trip, no extra latency. Empty ranges are dropped in formatFacets().
                $aggs['price'] = [
                    'range' => [
                        'field' => $priceField,
                        'ranges' => [
                            ['to' => 25],
                            ['from' => 25, 'to' => 50],
                            ['from' => 50, 'to' => 100],
                            ['from' => 100, 'to' => 250],
                            ['from' => 250, 'to' => 500],
                            ['from' => 500, 'to' => 1000],
                            ['from' => 1000, 'to' => 2500],
                            ['from' => 2500, 'to' => 5000],
                            ['from' => 5000],
                        ],
                    ],
                ];
            }
        }
        return $aggs;
    }

    /**
     * The indexed price field (`price_<customerGroup>_<website>`) for the guest/default
     * customer group (0) only. Same conservative rule as
     * Plugin\GraphQl\SearchOsHydrationPlugin::resolveGuestPriceField(): any other group, or an
     * unresolvable website, returns null and the price facet is simply omitted rather than
     * aggregating on the wrong group's price.
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
     * Pull display docs from FastMagento's own index in one mget, preserving relevance order.
     *
     * @param mixed $client
     * @param int[] $ids
     * @param string $query raw user query, forwarded for variant swatch pre-selection
     * @return array<int, array<string, mixed>>
     */
    private function hydrateProducts($client, array $ids, string $query = ''): array
    {
        if (!$ids) {
            return [];
        }
        $byId = $this->mgetSource($client, $ids);

        // Warm-on-miss: the fulltext (matching) index and our serving index can drift out of
        // sync — most visibly mid-reindex, when the native fulltext index (blue-green, always
        // complete) matches a product our serving index hasn't projected yet. Without this, that
        // product would be silently dropped and the results grid would blank out DURING a reindex.
        // Instead, load each missing hit natively once (which read-through-indexes it into
        // OpenSearch via the repository plugin), then re-fetch. GET/mget by id is realtime in
        // OpenSearch, so the re-fetch sees the just-warmed doc immediately, and the next search is
        // a pure OS read. Bounded by page size, degrades silently — a reindex never hides products.
        $missing = array_values(array_filter($ids, static fn ($id) => !isset($byId[$id])));
        if ($missing) {
            $storeId = (int) $this->storeManager->getStore()->getId();
            foreach ($missing as $mid) {
                try {
                    $this->productRepository->getById($mid, false, $storeId, false);
                } catch (\Throwable $e) {
                    $this->writeLog->writeErrorLog(
                        '[FastMagento] search warm-on-miss failed for product ' . $mid . ': ' . $e->getMessage()
                    );
                }
            }
            $byId += $this->mgetSource($client, $missing);
        }

        $products = [];
        foreach ($ids as $id) {                 // keep relevance order from the search index
            if (isset($byId[$id])) {
                $products[] = $this->formatProduct($id, $byId[$id], $query);
            }
        }
        return $products;
    }

    /**
     * One mget against the serving index [entity_id => _source] for the docs that are present.
     *
     * @param mixed $client
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function mgetSource($client, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $response = $client->getOpenSearchClient()->mget([
            'index' => $this->openSearchConfig->getIndexName(),
            'body' => ['ids' => array_map('strval', $ids)],
        ]);

        $byId = [];
        foreach ($response['docs'] ?? [] as $doc) {
            if (!empty($doc['found']) && isset($doc['_source'])) {
                $byId[(int) $doc['_id']] = $doc['_source'];
            }
        }
        return $byId;
    }

    /**
     * @param array<string, mixed> $source
     * @param string $query the raw user query — used to pre-select configurable variant swatches
     * @return array<string, mixed>
     */
    private function formatProduct(int $id, array $source, string $query = ''): array
    {
        $price = (float) ($source['final_price'] ?? $source['price'] ?? 0);
        $regular = (float) ($source['price'] ?? $price);
        $image = $this->imageUrl((string) ($source['small_image'] ?? $source['thumbnail'] ?? $source['image'] ?? ''));

        // Search variant swatch pre-selection: when a configurable hit's query words
        // pin a specific swatch combination (e.g. "red 34c" color=Red, size=34C), carry
        // those option ids in the result URL so the PDP's native swatch renderer opens with
        // them selected (correct image/price), and show the matched child's image on the card.
        $selectedOptions = [];
        if (($source['type_id'] ?? '') === 'configurable' && $query !== '') {
            // Isolate the swatch match: a single malformed configurable doc must lose only its
            // own pre-selection, never empty the whole (otherwise-fine) result set.
            try {
                $match = $this->matchSelectedOptions($this->deriveVariants($id, $source), $query);
                if (!empty($match['selected_options'])) {
                    $selectedOptions = $match['selected_options'];
                    if (!empty($match['image'])) {
                        $image = $this->imageUrl((string) $match['image']);
                    }
                }
            } catch (\Throwable $e) {
                $this->writeLog->writeErrorLog(
                    '[FastMagento] swatch pre-select skipped for product ' . $id . ': ' . $e->getMessage()
                );
            }
        }

        return [
            'id' => $id,
            'sku' => (string) ($source['sku'] ?? ''),
            'name' => (string) ($source['name'] ?? ''),
            'url' => $this->productUrl($source, $selectedOptions),
            'image' => $image,
            'price' => $price,
            'regular_price' => $regular,
            'price_formatted' => $this->priceCurrency->format($price, false),
            'regular_price_formatted' => $regular > $price ? $this->priceCurrency->format($regular, false) : null,
            'in_stock' => (bool) ($source['is_in_stock'] ?? true),
            'selected_options' => $selectedOptions ?: null,
        ];
    }

    /**
     * Flatten a configurable hit's children into matchable variants:
     * [{id, in_stock, image, options:{code=>optionId}, labels:{code=>label}}].
     *
     * Derived from data already in the _source (no reindex): each child's
     * custom_attributes hold the raw super-attribute option ids, swatch_options maps
     * (attributeId, optionId) => label, and configurable_options_<id> ties attribute
     * code id. A precomputed `variants` array on the doc (if a future indexer adds one)
     * is used as-is.
     *
     * @param array<string, mixed> $source
     * @return array<int, array<string, mixed>>
     */
    private function deriveVariants(int $id, array $source): array
    {
        if (!empty($source['variants']) && is_array($source['variants'])) {
            return $source['variants'];
        }
        $children = $source['child_products'] ?? [];
        if (!is_array($children) || !$children) {
            return [];
        }

        // attribute code => attribute id, from the indexed super-attribute config
        $codeToAttrId = [];
        foreach ((array) ($source['configurable_options_' . $id] ?? []) as $sa) {
            $code = $sa['product_attribute']['attribute_code'] ?? null;
            $attrId = $sa['attribute_id'] ?? null;
            if ($code !== null && $attrId !== null) {
                $codeToAttrId[(string) $code] = (int) $attrId;
            }
        }
        if (!$codeToAttrId) {
            return [];
        }

        $swatch = (array) ($source['swatch_options'] ?? []);
        $variants = [];
        foreach ($children as $child) {
            $ca = (array) ($child['custom_attributes'] ?? []);
            $options = [];
            $labels = [];
            foreach ($codeToAttrId as $code => $attrId) {
                if (!isset($ca[$code]) || $ca[$code] === '') {
                    continue;
                }
                $optId = (string) $ca[$code];
                $options[$code] = $optId;
                $labels[$code] = (string) ($swatch[$attrId][$optId]['label'] ?? '');
            }
            if (!$options) {
                continue;
            }
            $variants[] = [
                'id' => (int) ($child['entity_id'] ?? 0),
                'in_stock' => (bool) ($child['is_in_stock'] ?? false),
                'image' => (string) ($child['image'] ?? $child['small_image'] ?? $child['thumbnail'] ?? ''),
                'options' => $options,
                'labels' => $labels,
            ];
        }
        return $variants;
    }

    /**
     * Pin the query to specific swatch options. For each super-attribute, an option is
     * selected only when EXACTLY ONE of its labels is fully present in the query (all the
     * label's tokens appear as query words) — ambiguous or unmatched attributes are left
     * open, so "red bra" pre-selects colour alone and "red 34c bra" pins both.
     *
     * @param array<int, array<string, mixed>> $variants
     * @return array{selected_options:array<string,string>,image:string,child_id:?int}|array{}
     */
    private function matchSelectedOptions(array $variants, string $query): array
    {
        if (!$variants) {
            return [];
        }
        $qTokens = $this->applySynonyms($this->tokenize($query));
        if (!$qTokens) {
            return [];
        }

        // code => optionId => label
        $byCode = [];
        foreach ($variants as $v) {
            foreach ((array) ($v['options'] ?? []) as $code => $optId) {
                $label = (string) ($v['labels'][$code] ?? '');
                if ($label !== '') {
                    $byCode[$code][(string) $optId] = $label;
                }
            }
        }

        $selected = [];
        foreach ($byCode as $code => $opts) {
            $matched = [];
            foreach ($opts as $optId => $label) {
                $labelTokens = $this->tokenize($label);
                if (!$labelTokens) {
                    continue;
                }
                if (!array_diff_key($labelTokens, $qTokens)) {   // every label token is a query word
                    $matched[(string) $optId] = true;
                }
            }
            if (count($matched) === 1) {
                $selected[$code] = (string) array_key_first($matched);
            }
        }
        if (!$selected) {
            return [];
        }

        // Representative child for the card image: matches every selected option, in stock preferred.
        $best = null;
        foreach ($variants as $v) {
            foreach ($selected as $code => $optId) {
                if ((string) ($v['options'][$code] ?? '') !== $optId) {
                    continue 2;
                }
            }
            $best = $v;
            if (!empty($v['in_stock'])) {
                break;
            }
        }

        return [
            'selected_options' => $selected,
            'image' => (string) ($best['image'] ?? ''),
            'child_id' => isset($best['id']) ? (int) $best['id'] : null,
        ];
    }

    /**
     * Widen a query token set with the admin thesaurus (`fastmagento/search/synonyms`, the same
     * groups used for fulltext relevance) so a synonym of an option label still pins the swatch —
     * e.g. a "lavender, purple, violet" group lets "lavender" select the Purple swatch. Safe by
     * construction: if the widening makes two option labels match, `matchSelectedOptions`' "exactly
     * one" rule leaves that attribute unpinned rather than guessing. Group terms are matched at the
     * word level (multi-word synonyms won't hit single tokens — fine for colours/sizes).
     *
     * @param array<string, true> $tokens
     * @return array<string, true>
     */
    private function applySynonyms(array $tokens): array
    {
        foreach ($this->relevanceConfig->getSynonymGroups() as $group) {
            foreach ($group as $term) {
                if (isset($tokens[$term])) {
                    foreach ($group as $t) {
                        $tokens[$t] = true;
                    }
                    break;
                }
            }
        }
        return $tokens;
    }

    /**
     * Lowercase word set of a string (punctuation-trimmed), for case-insensitive
     * subset matching of option labels against query words.
     *
     * @return array<string, true>
     */
    private function tokenize(string $value): array
    {
        $tokens = [];
        foreach (preg_split('/\s+/', mb_strtolower(trim($value))) ?: [] as $part) {
            $part = trim($part, " \t\n\r\0\x0B.,;:!?\"'()[]");
            if ($part !== '') {
                $tokens[$part] = true;
            }
        }
        return $tokens;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, string> $selectedOptions attribute code => option id, appended as
     *        query params so the PDP swatch renderer pre-selects them ($.parseQuery on load)
     */
    private function productUrl(array $source, array $selectedOptions = []): string
    {
        $path = (string) ($source['url_path'] ?? $source['url_key'] ?? '');
        if ($path === '') {
            $url = $this->storeManager->getStore()->getBaseUrl()
                . 'catalog/product/view/id/' . ($source['entity_id'] ?? '');
        } else {
            $suffix = (string) $this->scopeConfig->getValue(
                'catalog/seo/product_url_suffix',
                ScopeInterface::SCOPE_STORE
            );
            $url = $this->storeManager->getStore()->getBaseUrl() . ltrim($path, '/') . $suffix;
        }

        if ($selectedOptions) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($selectedOptions);
        }
        return $url;
    }

    private function imageUrl(string $path): string
    {
        if ($path === '' || $path === 'no_selection') {
            return '';
        }
        $media = $this->storeManager->getStore()
            ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
        return rtrim($media, '/') . '/catalog/product' . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $aggregations
     * @return array<int, array<string, mixed>>
     */
    private function formatFacets(array $aggregations): array
    {
        $facets = [];
        foreach ($aggregations as $code => $agg) {
            $options = [];
            foreach ($agg['buckets'] ?? [] as $bucket) {
                // A range aggregation's buckets carry `from`/`to` instead of a term `key`;
                // formatted separately since there is no "label" sub-hit for a numeric range.
                if (array_key_exists('from', $bucket) || array_key_exists('to', $bucket)) {
                    $option = $this->formatRangeOption($bucket);
                    if ($option !== null) {
                        $options[] = $option;
                    }
                    continue;
                }
                $option = [
                    'value' => (string) $bucket['key'],
                    'count' => (int) $bucket['doc_count'],
                ];
                // Resolve the option label from the bucket's top hit (attribute facets only;
                // the category facet has no sub-agg and is labelled from the tree downstream).
                $label = $this->bucketLabel($code, (string) $bucket['key'], $bucket);
                if ($label !== null) {
                    $option['label'] = $label;
                }
                $options[] = $option;
            }
            if ($options) {
                $facets[] = ['attribute' => $code, 'options' => $options];
            }
        }
        return $facets;
    }

    /**
     * Format a price-range aggregation bucket as a dash-separated numeric range
     * (e.g. "100-250"). The open-ended top bucket (no `to`) uses a large sentinel upper bound
     * rather than an open range, since the resolver's currency conversion expects two numbers.
     * Returns null (dropped by the caller) for an empty range.
     *
     * @param array<string, mixed> $bucket
     * @return array{value: string, label: string, count: int}|null
     */
    private function formatRangeOption(array $bucket): ?array
    {
        $count = (int) ($bucket['doc_count'] ?? 0);
        if ($count <= 0) {
            return null;
        }
        $from = isset($bucket['from']) ? (int) round((float) $bucket['from']) : 0;
        $to = isset($bucket['to']) ? (int) round((float) $bucket['to']) : self::PRICE_RANGE_OPEN_END;
        $range = $from . '-' . $to;
        return ['value' => $range, 'label' => $range, 'count' => $count];
    }

    /**
     * Human label for a facet bucket, pulled from the bucket's top hit _source (from OpenSearch —
     * never the DB). The native index stores option ids on {code} and labels on {code}_value, but
     * on a MULTI-value doc those two arrays are sorted independently (ids numeric, labels
     * alphabetic), so their positions do NOT correspond and an id can't be mapped to its label
     * from a multi-value hit. We therefore trust ONLY an unambiguous single-value hit (one id ==
     * the bucket key, one label) — always the case for select attributes. Returns null otherwise
     * (category facet, or a value we can't label safely), so the caller drops it rather than
     * showing a wrong or raw label.
     *
     * @param array<string, mixed> $bucket
     */
    private function bucketLabel(string $code, string $key, array $bucket): ?string
    {
        $source = $bucket['label']['hits']['hits'][0]['_source'] ?? null;
        if (!is_array($source)) {
            return null;
        }
        $ids = array_map('strval', (array) ($source[$code] ?? []));
        $labels = array_values((array) ($source[$code . '_value'] ?? []));
        if (count($ids) === 1 && count($labels) === 1 && $ids[0] === $key && $labels[0] !== '') {
            return (string) $labels[0];
        }
        return null;
    }

    private function getClient()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }

    /**
     * Magento's native fulltext index alias for the store (analyzed, search-visible only).
     */
    private function getSearchIndex(int $storeId): string
    {
        $prefix = (string) ($this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?: 'magento2');
        return $prefix . '_product_' . $storeId;
    }
}
