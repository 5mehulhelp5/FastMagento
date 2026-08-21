<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\ResourceModel\Quote\Item;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;

/**
 * Answers the cart's "do these products still exist?" question from OpenSearch.
 *
 * Magento\Quote\Model\ResourceModel\Quote\Item\Collection::_afterLoad() calls
 * removeItemsWithAbsentProducts(), which builds a product collection over the line items and runs
 * getAllIds() purely to find rows that no longer exist. On a cart served entirely from OpenSearch
 * that was the last SQL touching catalog_product_entity — a bare existence check for products
 * whose documents the serving layer is about to fetch anyway.
 *
 * WHY THIS CLASS AND NOT AN OVERRIDE OF THE CALLER
 * ------------------------------------------------
 * removeItemsWithAbsentProducts() is PRIVATE and runs BEFORE _assignProducts(), so it cannot be
 * skipped from the subclass and there is nothing to piggy-back on. Reimplementing _afterLoad()
 * would mean copying VersionControl\Collection's origData handling and its two event dispatches
 * into this module — core control flow that a Magento patch release would silently desynchronise.
 *
 * The factory that builds the collection is `protected`, so swapping in this subclass replaces
 * only the lookup, leaves core's control flow untouched, and keeps the fallback trivial.
 *
 * SAFETY
 * ------
 * A document present in the index is proof the product exists; the indexer removes documents on
 * delete. The reverse is NOT assumed: any id the index cannot vouch for sends the whole call to
 * the native query, so a stale or partial index can never make a real product look absent and
 * drop it out of somebody's cart.
 */
class AbsentCheckProductCollection extends ProductCollection
{
    /** @var int[]|null Ids captured from addIdFilter(), or null when the filter was not used. */
    private ?array $fmFilteredIds = null;

    /** @var bool Set once an OS lookup has been attempted, so one failure is not retried. */
    private bool $fmOsUnavailable = false;

    /**
     * @param mixed $productId
     * @param bool $exclude
     * @return $this
     */
    public function addIdFilter($productId, $exclude = false)
    {
        // Only the plain "these ids" form is useful to us; an exclusion filter means the caller
        // wants everything BUT these, which the index cannot answer from an id list.
        if (!$exclude && is_array($productId)) {
            $this->fmFilteredIds = array_values(array_unique(array_filter(array_map('intval', $productId))));
        } else {
            $this->fmFilteredIds = null;
        }

        return parent::addIdFilter($productId, $exclude);
    }

    /**
     * @param int|string|null $limit
     * @param int|string|null $offset
     * @return array
     */
    public function getAllIds($limit = null, $offset = null)
    {
        if ($limit !== null || $offset !== null || !$this->fmFilteredIds || $this->fmOsUnavailable) {
            return parent::getAllIds($limit, $offset);
        }

        try {
            $fetcher = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher::class);
            $docs = $fetcher->fetchByIds($this->fmFilteredIds);

            foreach ($this->fmFilteredIds as $id) {
                if (!isset($docs[$id])) {
                    // The index cannot vouch for this id. Do not conclude the product is gone —
                    // ask the database, which is the only thing entitled to that answer.
                    return parent::getAllIds($limit, $offset);
                }
            }

            return $this->fmFilteredIds;
        } catch (\Throwable $e) {
            $this->fmOsUnavailable = true;

            return parent::getAllIds($limit, $offset);
        }
    }
}
