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
        private readonly \Psr\Log\LoggerInterface $logger
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
        $result['facets'] = $this->labelFacets($result['facets']);
        $result['pages'] = (int) ceil($result['total'] / max(1, $pageSize));

        return $this->jsonFactory->create()->setData($result);
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
