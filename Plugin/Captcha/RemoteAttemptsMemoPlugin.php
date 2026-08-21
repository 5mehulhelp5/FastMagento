<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Captcha;

use Magento\Captcha\Model\ResourceModel\Log;

/**
 * Count a visitor's captcha attempts once per request instead of once per protected form.
 *
 * Captcha's DefaultModel::isRequired() asks isOverLimitAttempts() -> isOverLimitIpAttempt() ->
 * countAttemptsByRemoteAddress(), which queries captcha_log for this IP. A page carrying two
 * captcha-protected forms -- the login and the review form on a stock Luma product page -- asks
 * twice, and gets the same answer both times.
 *
 * The count is attempts recorded BEFORE this request; nothing in a page render adds to it, so the
 * second query cannot return anything the first did not. Memoised per request only, so a failed
 * login on the NEXT request still sees the incremented count and the limit still works.
 *
 * This is repeat-elimination, not a serving-layer change -- it is here because these two queries
 * were on every page of the store, product pages and category pages alike.
 */
class RemoteAttemptsMemoPlugin
{
    /** @var int|string|null */
    private $count = null;

    /**
     * @param Log $subject
     * @param callable $proceed
     * @return int|string
     */
    public function aroundCountAttemptsByRemoteAddress(Log $subject, callable $proceed)
    {
        if ($this->count === null) {
            $this->count = $proceed();
        }

        return $this->count;
    }
}
