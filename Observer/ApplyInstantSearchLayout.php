<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Removes native search results + layered navigation on the search page, but ONLY when the
 * FastMagento instant-search takeover is actually switched on.
 *
 * WHY THIS IS NOT LAYOUT XML ANY MORE
 * -----------------------------------
 * Layout XML cannot express a condition, so the removals used to be unconditional. That made the
 * failure mode much worse than "feature missing": native results were stripped, the replacement
 * grid never populated, and the visitor got HTTP 200 with an empty page. The most common cause
 * was simply a theme whose JS bootstrap FastMagento did not support.
 *
 * Removing conditionally means the worst case is now native Magento search — slower, but correct.
 */
class ApplyInstantSearchLayout implements ObserverInterface
{
    public const XML_PATH_ENABLED = 'fastmagento/search/instant_search_enabled';

    /**
     * Only the native product LIST is replaced. Layered navigation and the sidebar container it
     * lives in both stay.
     *
     * They used to be removed, on the reasoning that the grid draws its own facet column. That
     * cost two things. The theme's sidebar slot sat empty (320px of dead space on a 2columns-left
     * layout) while the JS rebuilt a narrower rail INSIDE the content column, so the results grid
     * got ~880px where the category listing gets ~1136px and the two pages could never line up.
     * And the hand-rolled rail could not look like the rest of the storefront without shipping a
     * parallel stylesheet that every theme would have to be re-taught.
     *
     * Keeping the block means the theme renders its OWN layered navigation markup — wrapper,
     * "Shop By" heading, collapsible groups, mobile toggle, whatever that theme does — and
     * fastmagento.js then refills the groups from the OpenSearch aggregation (see
     * `hydrateThemeFacets`). Native layered nav on a search page aggregates far less than we do
     * (2 category options and 1 colour here, against our 7 / 11 plus size), so its DATA is not
     * used; its MARKUP is. Filter clicks stay client-side, so as-you-type search and the
     * FastMagento facet settings keep working.
     */
    private const REMOVE_BLOCKS = [
        'search.result',
    ];

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return;
        }

        /** @var LayoutInterface|null $layout */
        $layout = $observer->getData('layout');
        if (!$layout instanceof LayoutInterface) {
            return;
        }

        // If our own block is absent the takeover did not apply (disabled at a narrower scope, a
        // theme that removed it, another extension owning the route). Removing native results in
        // that state is exactly the blank page this class exists to prevent.
        if (!$layout->getBlock('fastmagento.instant.search')) {
            return;
        }

        foreach (self::REMOVE_BLOCKS as $name) {
            if ($layout->getBlock($name)) {
                $layout->unsetElement($name);
            }
        }
    }
}
