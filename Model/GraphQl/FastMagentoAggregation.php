<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\GraphQl;

use Magento\Framework\Search\Response\Aggregation;

/**
 * Carries Model\Search\InstantSearch::formatFacets() output through the GraphQL SearchResult so
 * Plugin\GraphQl\AggregationsOsHydrationPlugin can build the GraphQL aggregation array directly
 * from it, bypassing core's layerBuilder entirely. That is deliberate: layerBuilder fatals
 * ("Undefined array key attribute_type") on any bucket whose attribute isn't a filterable EAV
 * attribute, which is exactly what native GraphQL aggregations crash on for this catalogue.
 * FastMagento's facets are OS-native (labels + counts straight from the index, no EAV lookup),
 * so they never hit that code path.
 *
 * EXTENDS the concrete Response\Aggregation (not just the interface): core's
 * LayeredNavigation\Builder\Aggregations\Category\IncludeDirectChildrenOnly::filter() runs over
 * the SearchResult aggregation and is return-type-hinted to the concrete class, so a bare
 * interface implementation TypeErrors there. We carry NO buckets (parent constructed empty) -
 * the real facet data rides in getFacets(), which the resolver plugin reads after detecting this
 * subclass by instanceof.
 */
class FastMagentoAggregation extends Aggregation
{
    /**
     * @param array<int, array{attribute: string, options: array<int, array<string, mixed>>}> $facets
     */
    public function __construct(private readonly array $facets)
    {
        parent::__construct([]);
    }

    /**
     * @return array<int, array{attribute: string, options: array<int, array<string, mixed>>}>
     */
    public function getFacets(): array
    {
        return $this->facets;
    }
}
