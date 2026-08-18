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
     * Native blocks/containers the OpenSearch grid replaces. The grid renders its own facet
     * sidebar, so leaving these in place would duplicate the filter column.
     */
    private const REMOVE_BLOCKS = [
        'search.result',
        'catalog.leftnav',
        'catalogsearch.leftnav',
    ];

    private const REMOVE_CONTAINERS = [
        'sidebar.main',
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

        foreach (self::REMOVE_CONTAINERS as $name) {
            if ($layout->isContainer($name)) {
                $layout->unsetElement($name);
            }
        }
    }
}
