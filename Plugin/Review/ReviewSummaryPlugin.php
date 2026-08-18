<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Review;

use Magento\Framework\Model\AbstractModel;
use Magento\Review\Model\ReviewSummary;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/**
 * Serve review stars from the indexed document instead of one query per product card.
 *
 * `ReviewSummary::appendSummaryDataToObject()` resolves a single product's rating, so a listing
 * pays one `review_entity_summary` read per card — the last per-product query on a listing after
 * the collection and the configurable price ceiling moved to OpenSearch.
 *
 * The indexer now projects `reviews_count` and `rating_summary` into the product doc, and it
 * writes 0/0 for a product with no reviews rather than omitting the keys. That distinction is
 * what makes this safe: "no reviews" is a real answer we can serve, while a doc indexed before
 * this change has neither key and correctly falls through to the database. Without it, every
 * unreviewed product would look like a cache miss and query anyway.
 */
class ReviewSummaryPlugin
{
    /**
     * @param callable $proceed
     * @param int $storeId
     * @param int $entityType
     * @return void
     */
    public function aroundAppendSummaryDataToObject(
        ReviewSummary $subject,
        callable $proceed,
        AbstractModel $object,
        int $storeId,
        int $entityType = 1
    ) {
        // entityType 1 is the product entity; anything else (customer reviews elsewhere) is not
        // something this index covers.
        if ($entityType !== 1 || !$object instanceof ShellNoEavProduct) {
            $proceed($object, $storeId, $entityType);
            return;
        }

        $count = $object->getData('reviews_count');
        $rating = $object->getData('rating_summary');

        if ($count === null || $rating === null) {
            // Doc predates review indexing — reindex fastmagento_product to serve these.
            $proceed($object, $storeId, $entityType);
            return;
        }

        $object->addData([
            'reviews_count' => (int) $count,
            'rating_summary' => (int) $rating,
        ]);
    }
}
