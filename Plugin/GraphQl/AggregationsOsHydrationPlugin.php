<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\GraphQl;

use Magento\CatalogGraphQl\Model\Resolver\Aggregations;
use Magento\Directory\Model\PriceCurrency;
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
        private readonly FacetHolder $facetHolder,
        private readonly PriceCurrency $priceCurrency
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
            // 'price' needs no special-casing here: 'price_bucket' / attribute_code='price' /
            // label='Price' all fall out of the generic rule below - only its OPTION values
            // (raw "from-to" numeric ranges) need currency conversion, done in buildOptions().
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
            } elseif ($code === 'price') {
                // Our plugin returns straight from aroundResolve, bypassing core's
                // Aggregations::getConvertedAndRoundedOptionValue() price post-processing - so
                // this has to do that same "from-to" -> currency conversion itself.
                // InstantSearch::formatRangeOption() gives us a raw numeric "from-to" value/label
                // (e.g. "100-250"); skip (not return null from the whole method) any range this
                // store's currency conversion can't parse, rather than surface a raw number.
                $converted = $this->convertPriceRange($value);
                if ($converted === null) {
                    continue;
                }
                [$value, $label] = $converted;
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
     * Convert a raw "from-to" numeric price range (e.g. "100-250", from
     * InstantSearch::formatRangeOption()) into the currency-formatted shape core's own
     * price_bucket produces: label is "convertedFrom-convertedTo"; value replaces the dash with
     * an underscore (matching core's own value encoding) so it round-trips as a single filter
     * value. Returns null for anything that doesn't parse as two numbers.
     *
     * @return array{0: string, 1: string}|null
     */
    private function convertPriceRange(string $range): ?array
    {
        $parts = explode('-', $range, 2);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }
        $label = $this->priceCurrency->convertAndRound((float) $parts[0])
            . '-' . $this->priceCurrency->convertAndRound((float) $parts[1]);
        return [str_replace('-', '_', $label), $label];
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
