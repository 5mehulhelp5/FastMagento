<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Review;

use Magento\Review\Block\Form as ReviewForm;

/**
 * Load the review form's rating set once per request instead of once per caller.
 *
 * Magento\Review\Block\Form::getRatings() builds and loads a Rating collection every time it is
 * called -- two queries each (rating + rating_option) -- and a product page calls it three times,
 * so six queries fetch the same handful of rows three times over.
 *
 * This is not a serving-layer change and has nothing to do with OpenSearch: the rating set is
 * store-scoped configuration that cannot change mid-request, so returning the first result to
 * every later caller is the same data by definition. It is here because FastMagento's whole
 * premise is that a product page should not talk to the database to render, and after the
 * catalogue queries were gone this was the largest remaining block of repeats.
 *
 * Memoised per request only -- no cache, nothing to invalidate.
 */
class RatingsMemoPlugin
{
    /** @var mixed|null */
    private $ratings = null;

    /**
     * @param ReviewForm $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundGetRatings(ReviewForm $subject, callable $proceed)
    {
        if ($this->ratings === null) {
            $this->ratings = $proceed();
        }

        return $this->ratings;
    }
}
