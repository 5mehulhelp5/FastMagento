<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

/**
 * The exploration slot's contract with InstantSearch: whether a request should fetch a wider
 * window than its page, how wide, and how to permute the ranked ids before the page is sliced
 * out. Core's default (NullExplorationWindow) is never active and never permutes; the
 * a companion module may swap in its own window through a DI preference.
 *
 */
interface ExplorationWindowInterface
{
    public function isActive(?int $storeId = null): bool;

    /** How many ranked ids to fetch when active, for a page of `$pageSize`. */
    public function windowSize(int $pageSize): int;

    /**
     * @param int[] $ids the ranked ids for the whole window
     * @return int[] the same ids, possibly re-ordered — never more, never fewer
     */
    public function permute(array $ids, int $pageSize, ?int $storeId = null): array;
}
