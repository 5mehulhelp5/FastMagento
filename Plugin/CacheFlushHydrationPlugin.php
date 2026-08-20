<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\State;
use Magento\Framework\App\Config\ScopeConfigInterface;
use ParkkTech\FastMagento\Helper\WriteLog;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Model\OptionDictionary;

/**
 * Re-prime FastMagento's caches immediately after a cache flush, instead of on a shopper's request.
 *
 * WHY
 * ---
 * `cache:flush` does not just empty caches, it hands the whole rebuild bill to whoever loads the
 * next page. Measured on 2.4.9 + Luma sample data, the first category page after a flush cost 123
 * queries against 34 for the same page warm — and a chunk of that is FastMagento's own option
 * dictionary rebuilding inside the request. That is the worst possible moment to pay it: a deploy
 * or an admin "Flush Magento Cache" click is exactly when real traffic is arriving, and the cost
 * lands on a shopper rather than on the operator who caused it.
 *
 * Priming here moves the work to the process that did the flushing (CLI, or the admin request that
 * clicked the button), where it is expected to take a moment and where nobody is waiting on a
 * product grid.
 *
 * SAFETY
 * ------
 * Never allowed to break a flush. Every failure is logged and swallowed: a store whose OpenSearch
 * is down must still be able to clear its cache. Gated by fastmagento/cache/warm_after_flush so an
 * operator scripting many flushes in a row can turn it off.
 */
class CacheFlushHydrationPlugin
{
    private const XML_PATH_WARM_AFTER_FLUSH = 'fastmagento/cache/warm_after_flush';

    /** Guards against re-entry when a single command flushes several cache-type groups. */
    private bool $done = false;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OptionDictionary $optionDictionary,
        private readonly EavConfig $eavConfig,
        private readonly Emulation $emulation,
        private readonly State $appState,
        private readonly StoreManagerInterface $storeManager,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * @param Manager $subject
     * @param array $result
     * @return array
     */
    public function afterFlush(Manager $subject, $result)
    {
        $this->hydrate();

        return $result;
    }

    /**
     * @param Manager $subject
     * @param array $result
     * @return array
     */
    public function afterClean(Manager $subject, $result)
    {
        $this->hydrate();

        return $result;
    }

    private function hydrate(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;

        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_WARM_AFTER_FLUSH)) {
            return;
        }

        try {
            // Prime INSIDE frontend emulation, once per store view.
            //
            // This is the whole trick. Priming from the CLI area writes cache entries the
            // storefront never looks up — measured, a bare CLI hydrate left 9 files in var/cache
            // where a single real page request produced 199, and the first shopper still paid for
            // all 71 EAV queries. Magento keys this cache by area and store scope, so the warm-up
            // only counts if it happens under the same area and store the storefront will run in.
            // The CLI process has no area code at all, and store emulation needs one before it
            // will start ("Area code is not set"). emulateAreaCode() supplies it for the duration
            // of the callback without permanently changing the process.
            $this->appState->emulateAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND, function () {
                foreach ($this->storeManager->getStores() as $store) {
                    $storeId = (int) $store->getId();
                    $this->emulation->startEnvironmentEmulation(
                        $storeId,
                        \Magento\Framework\App\Area::AREA_FRONTEND,
                        true
                    );
                    try {
                        $this->primeStore();
                    } finally {
                        $this->emulation->stopEnvironmentEmulation();
                    }
                }
            });
        } catch (\Throwable $e) {
            // A flush must always succeed, even with OpenSearch down.
            $this->writeLog->writeErrorLog(
                '[FastMagento] post-flush cache hydration skipped: ' . $e->getMessage()
            );
        }
    }

    /**
     * The actual warm-up, run once per store view inside frontend emulation.
     */
    private function primeStore(): void
    {
        try {
            // 1. EAV attribute metadata — by far the biggest post-flush cost. A cache-cold
            //    category page spent 71 of its queries re-reading eav_attribute and friends;
            //    warm, the same page spends 3. Magento\Eav\Model\Config caches the whole
            //    per-entity attribute set on first access, so touching it once here is what
            //    moves those queries off the first shopper request.
            foreach ([\Magento\Catalog\Model\Product::ENTITY, \Magento\Catalog\Model\Category::ENTITY] as $entityType) {
                $this->eavConfig->getEntityAttributes($entityType);
            }

            // 2. FastMagento's own option dictionary.
            if (!$this->optionDictionary->isEnabled()) {
                return;
            }
            $ids = $this->optionDictionary->getAttributeIdsByCode();
            foreach ($ids as $attributeId) {
                $this->optionDictionary->getOptions((int) $attributeId);
            }
        } catch (\Throwable $e) {
            // A flush must always succeed, even with OpenSearch down.
            $this->writeLog->writeErrorLog(
                '[FastMagento] post-flush cache hydration skipped: ' . $e->getMessage()
            );
        }
    }
}
