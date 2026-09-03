<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Search;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryDataProvider;
use ParkkTech\FastMagento\Model\Search\InstantSearch;

/**
 * Instant-search results endpoint (/fastmagento/search/instant?q=&p=&filter[code][]=): the
 * paged product grid + facet payload that the results page re-renders in place as the user
 * types or toggles filters — no full page reload, Algolia-style.
 */
class Instant extends Action
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly InstantSearch $instantSearch,
        private readonly CategoryDataProvider $categoryData,
        private readonly \ParkkTech\FastMagento\Model\OptionDictionary $optionDictionary,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \ParkkTech\FastMagento\Model\Analytics\EventRecorderInterface $events
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $query = (string) $request->getParam('q', '');
        $page = (int) $request->getParam('p', 1);
        $pageSize = (int) $request->getParam('page_size', 12);

        $filters = [];
        foreach ((array) $request->getParam('filter', []) as $code => $values) {
            $filters[(string) $code] = is_array($values) ? $values : explode(',', (string) $values);
        }

        $result = $this->instantSearch->search($query, $page, $pageSize, $filters, true);

        // Record what was asked for, once the search has actually run so the result count is real.
        // Both signals land here: a typed query, and any facet narrowing applied alongside it.
        // Only on the first page — paging through results is the same intent, not a new one.
        if ($page === 1) {
            if ($query !== '') {
                $this->events->recordSearch($query, $filters, (int) ($result['total'] ?? 0));
            } elseif ($filters) {
                $this->events->recordFacetSelection($filters);
            }
        }
        $result['facets'] = $this->labelFacets($result['facets']);
        $result['pages'] = (int) ceil($result['total'] / max(1, $pageSize));
        $result['stateHtml'] = $this->renderState($query, $filters, $result['facets']);

        return $this->jsonFactory->create()->setData($result);
    }

    /**
     * "Currently filtering by" markup, rendered through the ACTIVE THEME's
     * layer/state.phtml so the chips, remove buttons and "Clear All" look native without the
     * extension shipping any markup for them. Empty string when nothing is applied — the theme's
     * template returns early in that case anyway.
     *
     * It is rendered per response rather than once per page load because the instant grid applies
     * filters client-side: no page load happens for the server to re-render on.
     *
     * @param array<string, string[]> $filters code => applied option values
     * @param array<int, array<string, mixed>> $facets labelled facets from labelFacets()
     */
    private function renderState(string $query, array $filters, array $facets): string
    {
        if (!$filters) {
            return '';
        }

        $labels = [];
        foreach ($facets as $facet) {
            $code = (string) ($facet['attribute'] ?? '');
            $labels[$code] = ['name' => (string) ($facet['label'] ?? $code), 'options' => []];
            foreach ($facet['options'] ?? [] as $option) {
                $labels[$code]['options'][(string) $option['value']] = (string) $option['label'];
            }
        }

        $items = [];
        foreach ($filters as $code => $values) {
            foreach ($values as $value) {
                $value = (string) $value;
                if ($value === '') {
                    continue;
                }
                $items[] = new \Magento\Framework\DataObject([
                    'name' => $labels[$code]['name'] ?? $code,
                    // An applied option can drop out of its own facet, so fall back to the raw id
                    // rather than rendering a chip with no label.
                    'label' => $labels[$code]['options'][$value] ?? $value,
                    'remove_url' => $this->resultsUrl($query, $this->without($filters, $code, $value)),
                ]);
            }
        }

        if (!$items) {
            return '';
        }

        try {
            return (string) $this->_view->getLayout()
                ->createBlock(\ParkkTech\FastMagento\Block\Search\InstantState::class)
                ->setData('active_filters', $items)
                ->setData('clear_url', $this->resultsUrl($query, []))
                ->toHtml();
        } catch (\Throwable $e) {
            // A theme without the template, or one whose override needs something we do not
            // provide: the grid and facets still work, only the chips are missing.
            $this->logger->error('FastMagento instant-search state render failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * The applied set with one option removed, dropping the code entirely when it was the last.
     *
     * @param array<string, string[]> $filters
     * @return array<string, string[]>
     */
    private function without(array $filters, string $code, string $value): array
    {
        $copy = $filters;
        $copy[$code] = array_values(array_filter(
            $copy[$code] ?? [],
            static fn ($candidate) => (string) $candidate !== $value
        ));
        if (!$copy[$code]) {
            unset($copy[$code]);
        }

        return $copy;
    }

    /**
     * A results-page URL for a query + applied set, in the same `filter[code]=a,b` shape the
     * storefront JS reads back, so these links work with JS off and are what it adopts on click.
     *
     * @param array<string, string[]> $filters
     */
    private function resultsUrl(string $query, array $filters): string
    {
        $params = ['q' => $query];
        foreach ($filters as $code => $values) {
            if ($values) {
                $params['filter'][$code] = implode(',', $values);
            }
        }

        return $this->_url->getUrl('catalogsearch/result', ['_query' => $params, '_use_rewrite' => true]);
    }

    /**
     * Finalise facet display. Option labels already come from OpenSearch (InstantSearch pulls
     * {code}_value from the native index _source), so here we only: set the facet heading, resolve
     * category ids to names from the OpenSearch category tree, and drop options that carry no
     * label (a stray id) or root/site categories. No DB/EAV lookups — everything is OS-served.
     *
     * @param array<int, array<string, mixed>> $facets
     * @return array<int, array<string, mixed>>
     */
    private function labelFacets(array $facets): array
    {
        foreach ($facets as &$facet) {
            $code = (string) ($facet['attribute'] ?? '');
            $isCategory = $code === 'category';
            $facet['label'] = $isCategory ? 'Category' : ucwords(str_replace('_', ' ', $code));
            $unresolved = 0;
            $total = count($facet['options']);
            foreach ($facet['options'] as &$option) {
                if ($isCategory) {
                    $doc = $this->categoryData->getById((int) $option['value']);
                    $option['label'] = $doc['name'] ?? $option['value'];
                    if (!$doc || (int) ($doc['level'] ?? 0) < 2) {
                        $option['skip'] = true;   // root/site categories
                    }
                } elseif (($option['label'] ?? '') === '') {
                    // InstantSearch can only label a bucket from an unambiguous single-value hit.
                    // Every configurable parent is multi-value (it carries all its children's
                    // colours/sizes), so on a configurable catalog EVERY option arrived here
                    // unlabelled and the whole facet was dropped below. The option dictionary is
                    // an explicit id => label map built by the fastmagento_attribute_option
                    // indexer, which resolves exactly this case — still OpenSearch-served, no EAV.
                    $resolved = $this->optionDictionary->getOptionTextByCode($code, (string) $option['value']);
                    if ($resolved !== null && $resolved !== '') {
                        $option['label'] = $resolved;
                    } else {
                        $option['skip'] = true;   // genuinely unresolvable — never show a raw id
                        $unresolved++;
                    }
                }
            }
            unset($option);
            // A facet that vanishes because nothing could be labelled used to be silent — the
            // single hardest symptom to diagnose from the outside. Say so once, per facet.
            if (!$isCategory && $unresolved > 0 && $unresolved === $total) {
                $this->logger->warning(sprintf(
                    '[FastMagento] Facet "%s" dropped: none of its %d option(s) could be labelled. '
                    . 'Run: bin/magento indexer:reindex fastmagento_attribute_option',
                    $code,
                    $total
                ));
            }
            $facet['options'] = array_values(array_filter(
                $facet['options'],
                static fn ($o) => empty($o['skip'])
            ));
        }
        unset($facet);

        return array_values(array_filter($facets, static fn ($f) => !empty($f['options'])));
    }
}
