<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Model\OpenSearch\InstantProductUpdater;

/**
 * Once a product save commits, when Instant Product Update is enabled, reproject the product into
 * OpenSearch and purge its full-page cache immediately — so an admin/staff edit is live on the
 * storefront at once instead of after the scheduled indexer + cache lag. Bound to
 * catalog_product_save_commit_after. Gated + best-effort; never blocks a save.
 */
class InstantUpdateOnSave implements ObserverInterface
{
    public function __construct(
        private readonly OpenSearchConfig $config,
        private readonly InstantProductUpdater $updater
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isInstantProductUpdateEnabled()) {
            return;
        }

        $product = $observer->getEvent()->getProduct();
        if ($product instanceof Product) {
            $this->updater->updateOnSave($product);
        }
    }
}
