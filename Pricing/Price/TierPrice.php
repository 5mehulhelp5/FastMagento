<?php

namespace ParkkTech\FastMagento\Pricing\Price;

use Magento\Catalog\Pricing\Price\TierPrice as CoreTierPrice;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class TierPrice extends CoreTierPrice
{
    public function getValue()
    {
        $product = $this->getProduct();

        // ✅ Use OpenSearch instead of SQL only for frontend products
        if ($product instanceof ShellNoEavProduct) {
            return $product->getTierPrices();
        }

        return parent::getValue();
    }
}
