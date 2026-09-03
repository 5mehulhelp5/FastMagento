<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Analytics;

/**
 * Where the instant-search controller reports what a shopper searched for and which facets they
 * chose. Core's default (NullEventRecorder) records nothing; a companion module may swap in its own
 * recorder through a DI preference.
 *
 */
interface EventRecorderInterface
{
    public function recordSearch(string $query, array $filters = [], int $resultCount = 0): void;

    /** @return bool true when a selection was recorded */
    public function recordFacetSelection(array $filters, ?string $query = null): bool;
}
