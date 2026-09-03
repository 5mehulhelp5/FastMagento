<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

/**
 * Core's default exploration window: never active, so InstantSearch fetches exactly one page
 * and slices nothing. windowSize()/permute() are safe identities in case a caller asks anyway.
 */
class NullExplorationWindow implements ExplorationWindowInterface
{
    public function isActive(?int $storeId = null): bool
    {
        return false;
    }

    public function windowSize(int $pageSize): int
    {
        return $pageSize;
    }

    public function permute(array $ids, int $pageSize, ?int $storeId = null): array
    {
        return array_values($ids);
    }
}
