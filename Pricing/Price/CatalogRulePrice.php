<?php

namespace ParkkTech\FastMagento\Pricing\Price;

use Magento\CatalogRule\Pricing\Price\CatalogRulePrice as CoreCatalogRulePrice;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

class CatalogRulePrice extends CoreCatalogRulePrice
{
    public function getValue()
    {
        $product = $this->getProduct();

        // ✅ Use OpenSearch instead of SQL
        if ($product instanceof ShellNoEavProduct) {
            return $product->getData('catalog_rule_price')['rule_price'] ?? parent::getValue();
        }

        return parent::getValue();
    }
}
