<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\GraphQl;

/**
 * Request-scoped holder passing FastMagento-computed facets from
 * Plugin\GraphQl\SearchOsHydrationPlugin to Plugin\GraphQl\AggregationsOsHydrationPlugin.
 *
 * Needed because the aggregation object set on `Magento\Search\Api\SearchInterface::search()`'s
 * return value does not reliably survive to the `Aggregations` resolver: the value GraphQL's
 * field resolvers pass down gets rebuilt along the way, so a marker object riding on it cannot be
 * trusted to arrive intact. A plain DI-shared instance (Magento shares one instance per request
 * by default, no `shared="false"` declared) sidesteps that entirely.
 *
 * Consume-once: getFacets() clears the stashed value as it returns it, so a leftover value from
 * an earlier resolution within the same request can never be misread by an unrelated later one.
 */
class FacetHolder
{
    /** @var array<int, array{attribute: string, options: array<int, array<string, mixed>>}>|null */
    private ?array $facets = null;

    /**
     * @param array<int, array{attribute: string, options: array<int, array<string, mixed>>}> $facets
     */
    public function setFacets(array $facets): void
    {
        $this->facets = $facets;
    }

    /**
     * @return array<int, array{attribute: string, options: array<int, array<string, mixed>>}>|null
     *         null when nothing has been stashed (this search was not FastMagento-served).
     */
    public function takeFacets(): ?array
    {
        $facets = $this->facets;
        $this->facets = null;
        return $facets;
    }
}
