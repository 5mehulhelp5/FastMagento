<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Review;

use Magento\Framework\Registry;
use Magento\Review\Block\Product\Review as ReviewBlock;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/**
 * Answer "how many reviews does this product have?" from the indexed reviews_count.
 *
 * Magento\Review\Block\Product\Review computes its tab title in its CONSTRUCTOR, via
 * getCollectionSize() -> Collection::getSize() -> a COUNT over review joined to review_detail and
 * review_store. Because the work happens at construction, it runs once per instantiation of the
 * block rather than once per page -- on a stock product page the same COUNT is issued twice.
 *
 * ProductIndexer already projects reviews_count onto every product document, so this is a number
 * the page has in hand before it asks for it.
 *
 * Falls through whenever the registry product is not an OS-hydrated shell or carries no
 * reviews_count, so a native page and a pre-field index both behave exactly as before.
 */
class ReviewCollectionSizePlugin
{
    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * @param ReviewBlock $subject
     * @param callable $proceed
     * @return int
     */
    public function aroundGetCollectionSize(ReviewBlock $subject, callable $proceed)
    {
        try {
            $product = $this->registry->registry('current_product') ?: $this->registry->registry('product');
            if (!$product instanceof ShellNoEavProduct) {
                return $proceed();
            }

            $count = $product->getData('reviews_count');
            if ($count === null || $count === '') {
                return $proceed();
            }

            return (int) $count;
        } catch (\Throwable $e) {
            return $proceed();
        }
    }
}
