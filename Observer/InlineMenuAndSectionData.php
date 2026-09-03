<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Two render-time cuts for uncached page loads, applied after the layout has generated its
 * blocks and before anything renders. Theme-agnostic: it works on block NAMES, and the names
 * for Luma, Breeze (both use Magento's `catalog.topnav`) and Hyvä (`topmenu_generic`) are the
 * defaults; any other theme's menu block can be added in configuration.
 *
 * 1. Menu inline instead of ESI. Magento's Varnish mode turns any block with a `ttl` into an
 *    `<esi:include>`, which costs a second full Magento bootstrap, routing pass and web-server
 *    hop on every cache miss (~120 ms on the 2.4.9 demo, against ~150 ms for the page itself).
 *    The menu is block-cached anyway (Luma's Topmenu defaults to 3600 s; Hyvä sets it in
 *    layout), so dropping `ttl` keeps the menu HTML coming from the block cache while the page
 *    is assembled in one request. Built-in full-page-cache mode already renders it inline, so
 *    this only changes anything under Varnish, and only how the fragment is delivered.
 *
 * 2. Hyvä's default-section-data block cached per store. It serialises the guest section data,
 *    of which only `directory-data` (every country and region) is computed live — store-scoped,
 *    identical for every visitor, ~28 ms and two queries per uncached page. It is already
 *    inside the full-page cache, so per-visitor variation is impossible by construction.
 */
class InlineMenuAndSectionData implements ObserverInterface
{
    public const XML_PATH_INLINE_MENU = 'fastmagento/serving/inline_menu';
    public const XML_PATH_INLINE_MENU_BLOCKS = 'fastmagento/serving/inline_menu_blocks';
    public const XML_PATH_CACHE_SECTION_DATA = 'fastmagento/serving/cache_section_data';

    private const SECTION_DATA_BLOCK = 'default-section-data';
    private const LIFETIME = 3600;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $layout = $observer->getEvent()->getLayout();
            if (!$layout) {
                return;
            }
            if ($this->scopeConfig->isSetFlag(self::XML_PATH_INLINE_MENU, ScopeInterface::SCOPE_STORE)) {
                foreach ($this->menuBlockNames() as $name) {
                    $block = $layout->getBlock($name);
                    if ($block instanceof AbstractBlock) {
                        $this->inline($block);
                    }
                }
            }
            if ($this->scopeConfig->isSetFlag(self::XML_PATH_CACHE_SECTION_DATA, ScopeInterface::SCOPE_STORE)) {
                $block = $layout->getBlock(self::SECTION_DATA_BLOCK);
                if ($block instanceof AbstractBlock) {
                    $this->cacheSectionData($block);
                }
            }
        } catch (\Throwable $e) {
            // Rendering must never depend on this observer; the page just renders the native way.
            $this->logger->debug('[FastMagento] inline menu / section data observer: ' . $e->getMessage());
        }
    }

    private function inline(AbstractBlock $block): void
    {
        if ($block->hasData('ttl')) {
            $block->unsetData('ttl');
        }
        // A menu block that was only ever cached by Varnish (via its ttl) still needs a block
        // cache lifetime, or every miss would rebuild the tree. Topmenu subclasses answer 3600
        // on their own; anything else gets the same default.
        if (!$block->hasData('cache_lifetime')) {
            $lifetime = (function () {
                return $this->getCacheLifetime();
            })->call($block);
            if ($lifetime === null || $lifetime === false) {
                $block->setData('cache_lifetime', self::LIFETIME);
            }
        }
    }

    private function cacheSectionData(AbstractBlock $block): void
    {
        $store = $this->storeManager->getStore();
        $block->setData('cache_lifetime', self::LIFETIME);
        $block->setData('cache_key', preg_replace(
            '/[^a-z0-9\-_]/i',
            '_',
            'fastmagento_section_data_' . $store->getId() . '_' . $store->getCurrentCurrencyCode()
        ));
    }

    /**
     * @return string[]
     */
    private function menuBlockNames(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_INLINE_MENU_BLOCKS, ScopeInterface::SCOPE_STORE);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
