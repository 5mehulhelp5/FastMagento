<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\ConfigurableMaxPriceCalculator;
use Magento\Framework\Registry;

/**
 * Serve a configurable's maximum child price from the indexed children instead of MySQL.
 *
 * `ConfigurableMaxPriceCalculator::getMaxPriceForConfigurableProduct()` runs a
 * `MAX(ip.final_price)` join across `catalog_product_super_link` -> `catalog_product_entity` ->
 * `catalog_product_index_price` for ONE product, so a listing of configurables pays it once per
 * card — twelve queries on a twelve-product page, which is what remained after the listing itself
 * moved to OpenSearch.
 *
 * The data is already in hand: when a configurable is hydrated from the index its children are
 * built as shells and registered under `child_products_<parentId>`, each carrying the indexed
 * final price (catalog rule and special price already applied, which is exactly what
 * `ip.final_price` represents). Reading the max from those costs no I/O at all — not even an
 * OpenSearch round trip.
 *
 * Note this takes only a product id, so the guard is the registry itself: if no shells are
 * registered for that id — a native product, an unindexed one, the admin — we defer to core.
 */
class ConfigurableMaxPriceCalculatorPlugin
{
    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * @param callable $proceed
     * @param int $productId
     * @return float
     */
    public function aroundGetMaxPriceForConfigurableProduct(
        ConfigurableMaxPriceCalculator $subject,
        callable $proceed,
        $productId
    ) {
        $shells = $this->registry->registry('child_products_' . (int) $productId);
        if (!is_array($shells) || !$shells) {
            return $proceed($productId);
        }

        $max = null;
        foreach ($shells as $child) {
            if (!$child instanceof ProductInterface) {
                // A mixed bag means we cannot trust the set to be complete; a max computed from
                // part of the children would silently understate the range on the storefront.
                return $proceed($productId);
            }
            $price = (float) $child->getFinalPrice();
            if ($price > 0 && ($max === null || $price > $max)) {
                $max = $price;
            }
        }

        // Every child priced at zero is indistinguishable from "not priced yet" here, so let core
        // answer rather than publishing a 0.00 ceiling.
        return $max ?? $proceed($productId);
    }
}
