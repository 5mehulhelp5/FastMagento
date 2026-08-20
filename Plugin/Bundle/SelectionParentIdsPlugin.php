<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Bundle;

use Magento\Bundle\Model\ResourceModel\Selection;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;

/**
 * Answer "which bundles contain this product?" from OpenSearch instead of MySQL.
 *
 * WHY THIS EXISTS
 * ---------------
 * Magento_Bundle observes `catalog_product_upsell` and, on EVERY product page, asks
 * Selection::getParentIdsByChild() whether the product is a selection inside some bundle. For the
 * overwhelming majority of catalogues the answer is "no" — but learning that still costs a
 * catalog_product_bundle_selection query on every PDP render. It was the last catalogue query on a
 * product page after the link graph moved into the index.
 *
 * ProductIndexer projects the answer onto the product document as `fm_bundle_parents`, so an empty
 * list is a real indexed answer rather than something we have to go and discover. Missing field,
 * missing document or any error falls straight through to the native query, so a stale index can
 * never hide a bundle from the up-sell block.
 *
 * Scoped to the frontend: admin and CLI keep the native path, where correctness against
 * uncommitted writes matters more than the query.
 */
class SelectionParentIdsPlugin
{
    /** @var array<int, int[]|false> child id => indexed parent ids, or false when unusable. */
    private array $cache = [];

    public function __construct(
        private readonly State $appState,
        private readonly OpenSearchPdpFetcher $fetcher
    ) {
    }

    /**
     * @param Selection $subject
     * @param callable $proceed
     * @param int|string $childId
     * @return array
     */
    public function aroundGetParentIdsByChild(Selection $subject, callable $proceed, $childId)
    {
        try {
            if ($this->appState->getAreaCode() !== 'frontend') {
                return $proceed($childId);
            }
        } catch (\Throwable $e) {
            return $proceed($childId);
        }

        // Core calls this with a scalar on the product page; anything else (an array of ids from
        // a bulk caller) is not what this fast path was measured for.
        if (is_array($childId) || !is_numeric($childId)) {
            return $proceed($childId);
        }

        $id = (int) $childId;
        if (!array_key_exists($id, $this->cache)) {
            $parents = false;
            try {
                $doc = $this->fetcher->fetchPdpById($id);
                if (is_array($doc) && array_key_exists('fm_bundle_parents', $doc)) {
                    $parents = array_values(array_map('intval', (array) $doc['fm_bundle_parents']));
                }
            } catch (\Throwable $e) {
                $parents = false;
            }
            $this->cache[$id] = $parents;
        }

        if ($this->cache[$id] === false) {
            return $proceed($childId);
        }

        return $this->cache[$id];
    }
}
