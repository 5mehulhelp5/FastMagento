<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Inventory;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\Plugin\AfterProductLoad;
use ParkkTech\FastMagento\Model\ServingGateAware;

/**
 * Restores core's catalogInventoryAfterLoad plugin while the serving master switch is off.
 *
 * FastMagento disables that core plugin on the storefront (etc/frontend/di.xml) because served
 * products carry their stock item from the index. With serving switched off, products are loaded
 * natively and need core's stock item again, so this runs core's plugin in that case only.
 */
class GatedAfterProductLoadPlugin
{
    use ServingGateAware;

    public function __construct(private readonly AfterProductLoad $coreAfterLoad)
    {
    }

    public function afterLoad(Product $subject, $result)
    {
        if ($this->isServingEnabled()) {
            return $result;
        }
        return $this->coreAfterLoad->afterLoad($subject);
    }
}
