<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Analytics;

/**
 * Core's default event recorder: nothing is recorded.
 */
class NullEventRecorder implements EventRecorderInterface
{
    public function recordSearch(string $query, array $filters = [], int $resultCount = 0): void
    {
    }

    public function recordFacetSelection(array $filters, ?string $query = null): bool
    {
        return false;
    }
}
