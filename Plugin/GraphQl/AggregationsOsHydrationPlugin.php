<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\GraphQl;

use Magento\CatalogGraphQl\Model\Resolver\Aggregations;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Model\GraphQl\FacetHolder;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryDataProvider;

/**
 * Build GraphQL layered-nav aggregations directly from FastMagento's OS-native facets
 * (see {@see \ParkkTech\FastMagento\Model\Search\InstantSearch::formatFacets()}) instead of
 * core's `layerBuilder`, which fatals ("Undefined array key attribute_type" in
 * DataProvider\Product\LayeredNavigation\Builder\Attribute::build()) on any bucket whose
 * attribute isn't a filterable EAV attribute - exactly what native GraphQL aggregations crash
 * on for this catalogue.
 *
 * Only takes over when {@see SearchOsHydrationPlugin} actually served this search - detected via
 * the request-scoped {@see FacetHolder} it stashes its facets in (the aggregation object set
 * directly on the search result does not reliably survive to reach this resolver - see
 * FacetHolder's docblock). Every other case (flag off, native-served search, no facets, or the
 * holder is empty) defers to native untouched.
 */
class AggregationsOsHydrationPlugin
{
    public function __construct(
        private readonly CategoryDataProvider $categoryData,
        private readonly WriteLog $writeLog,
        private readonly FacetHolder $facetHolder
    ) {
    }

    /**
     * @param Aggregations $subject
     * @param callable $proceed
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundResolve(
        Aggregations $subject,
        callable $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        try {
            $facets = $this->facetHolder->takeFacets();
            if ($facets === null) {
                return $proceed($field, $context, $info, $value, $args);
            }

            return $this->buildAggregations($facets);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                'GraphQL OS aggregations serve failed; native fallback: ' . $e->getMessage()
            );
            return $proceed($field, $context, $info, $value, $args);
        }
    }

    /**
     * Map InstantSearch's formatFacets() output to the resolver's own output shape: an array
     * keyed by bucket name (`<attribute_code>_bucket`, `category_bucket`), each entry carrying
     * label/attribute_code/count/options/position.
     *
     * @param array<int, array{attribute: string, options: array<int, array<string, mixed>>}> $facets
     * @return array<string, array<string, mixed>>
     */
    private function buildAggregations(array $facets): array
    {
        $result = [];
        foreach ($facets as $facet) {
            $code = (string) ($facet['attribute'] ?? '');
            if ($code === '') {
                continue;
            }
            $options = $this->buildOptions($code, (array) ($facet['options'] ?? []));
            if (!$options) {
                continue;
            }
            $isCategory = $code === 'category';
            $bucketName = $isCategory ? 'category_bucket' : $code . '_bucket';
            $result[$bucketName] = [
                'label' => $isCategory ? 'Category' : $this->humanizeLabel($code),
                'attribute_code' => $isCategory ? 'category_id' : $code,
                'count' => count($options),
                'options' => $options,
                'position' => null,
            ];
        }
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rawOptions
     * @return array<int, array{label: string, value: string, count: int}>
     */
    private function buildOptions(string $code, array $rawOptions): array
    {
        $options = [];
        foreach ($rawOptions as $option) {
            $value = (string) ($option['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $label = $option['label'] ?? null;
            if ($code === 'category') {
                // Category facet options carry no label from OpenSearch (see
                // InstantSearch::bucketLabel() - the category doc has no matching "_value"
                // field); resolve the name from the same OS-native category dictionary the
                // frontend mega-menu/breadcrumbs already use, so this stays EAV-free too.
                $doc = $this->categoryData->getById((int) $value);
                $label = $doc['name'] ?? $value;
            } elseif ($label === null || $label === '') {
                // Attribute facet option whose OS bucket label could not be resolved (the
                // indexed doc has no matching "<code>_value" option label). The storefront
                // facet UI skips these; do the same so GraphQL facets mirror it exactly rather
                // than surfacing a raw option id as the label. A bucket left with no labelled
                // options is then dropped by buildAggregations().
                continue;
            }
            $options[] = [
                'label' => (string) $label,
                'value' => $value,
                'count' => (int) ($option['count'] ?? 0),
            ];
        }
        return $options;
    }

    /**
     * Readable fallback label for a facet attribute (e.g. "shock_spacing" -> "Shock Spacing").
     * FastMagento's OS-native facets carry no EAV attribute-label metadata by design, so there
     * is no attribute frontend label to read here; this matches what the storefront's own
     * facet UI already shows for the same attribute codes.
     */
    private function humanizeLabel(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }
}
