<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Block-caches the layered navigation HTML, keyed on everything that changes it.
 *
 * With the facets already answered from the index, what the navigation still costs on an
 * uncached listing is pure template time (~30 ms on the 2.4.9 Hyvä demo: building filter
 * items, swatch renderers, the active-filter state). That output is a function of the
 * category (or search query), the applied filter parameters, store, currency and customer
 * group — nothing per visitor beyond what the full-page cache already varies on — so it can
 * be cached like any other block.
 *
 * Applying the filters to the product collection is NOT skipped: Navigation::_prepareLayout()
 * does that before rendering, and a cache hit only replaces the rendering.
 *
 * Theme-agnostic by block name: `catalog.leftnav` and `catalogsearch.leftnav` are Magento's
 * names, used unchanged by Luma, Breeze and Hyvä; others can be configured.
 *
 * Staleness: tagged with the category (status and stock changes, new products, category
 * saves and catalogue reindexes all clean it) and bounded by the lifetime; an attribute
 * value edited on an existing product changes only that product's tag, so a facet count can
 * lag by up to the lifetime — the same bound the full-page cache already puts on that page.
 */
class CacheLayeredNavigation implements ObserverInterface
{
    public const XML_PATH_ENABLED = 'fastmagento/serving/cache_layered_nav';
    public const XML_PATH_LIFETIME = 'fastmagento/serving/cache_layered_nav_lifetime';
    public const XML_PATH_BLOCKS = 'fastmagento/serving/cache_layered_nav_blocks';

    /** Request parameters that change the listing but not the navigation. */
    private const NON_FILTER_PARAMS = [
        'p', 'product_list_limit', 'product_list_order', 'product_list_dir', 'product_list_mode',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly HttpContext $httpContext,
        private readonly Registry $registry,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
                return;
            }
            $layout = $observer->getEvent()->getLayout();
            if (!$layout) {
                return;
            }
            foreach ($this->blockNames() as $name) {
                $block = $layout->getBlock($name);
                if ($block instanceof AbstractBlock) {
                    $this->cache($block);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[FastMagento] layered navigation cache observer: ' . $e->getMessage());
        }
    }

    private function cache(AbstractBlock $block): void
    {
        $store = $this->storeManager->getStore();
        $category = $this->registry->registry('current_category');
        $categoryId = $category instanceof Category ? (int) $category->getId() : 0;

        $params = $this->request->getParams();
        foreach (self::NON_FILTER_PARAMS as $skip) {
            unset($params[$skip]);
        }
        ksort($params);

        $keyInfo = [
            'fastmagento_lnav',
            $block->getNameInLayout(),
            (int) $store->getId(),
            (string) $store->getCurrentCurrencyCode(),
            (string) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP),
            $categoryId,
            $this->request->getFullActionName(),
            $params,
        ];
        $tags = [Category::CACHE_TAG, Product::CACHE_TAG];
        if ($categoryId) {
            $tags[] = Category::CACHE_TAG . '_' . $categoryId;
        }

        $lifetime = (int) $this->scopeConfig->getValue(self::XML_PATH_LIFETIME, ScopeInterface::SCOPE_STORE);
        $block->setData('cache_lifetime', $lifetime > 0 ? $lifetime : 3600);
        $block->setData('cache_key', 'fastmagento_lnav_' . sha1(json_encode($keyInfo)));
        $block->setData('cache_tags', array_merge((array) $block->getData('cache_tags'), $tags));
    }

    /**
     * @return string[]
     */
    private function blockNames(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_BLOCKS, ScopeInterface::SCOPE_STORE);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
