<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Msrp;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Msrp\Model\Msrp;

/**
 * Resolve MSRP's apply-to list through the cached EAV config instead of a direct attribute load.
 *
 * Msrp::canApplyToProduct() memoises its answer for the life of the object, but the first call
 * does `eavAttributeFactory->create()->loadByCode(Product::ENTITY, 'msrp')` -- and loadByCode()
 * always goes to the database: entity type, attribute row, the additional-attribute table name,
 * then catalog_eav_attribute. Four queries on every product page to read one static piece of
 * attribute metadata.
 *
 * Magento\Eav\Model\Config::getAttribute() answers the same question from the eav cache, which is
 * populated once and shared across requests. Same source of truth, same value -- just the cached
 * road to it.
 *
 * Falls through to core whenever the attribute cannot be resolved or carries no apply_to, so the
 * behaviour on an unusual setup is exactly what it was.
 */
class CanApplyToProductPlugin
{
    /** @var string[]|null */
    private ?array $applyTo = null;

    public function __construct(private readonly EavConfig $eavConfig)
    {
    }

    /**
     * @param Msrp $subject
     * @param callable $proceed
     * @param mixed $product
     * @return bool
     */
    public function aroundCanApplyToProduct(Msrp $subject, callable $proceed, $product)
    {
        try {
            if ($this->applyTo === null) {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, 'msrp');
                $applyTo = $attribute ? $attribute->getApplyTo() : null;
                if (!$applyTo) {
                    return $proceed($product);
                }
                $this->applyTo = is_array($applyTo) ? $applyTo : explode(',', (string) $applyTo);
            }

            return in_array($product->getTypeId(), $this->applyTo, false);
        } catch (\Throwable $e) {
            return $proceed($product);
        }
    }
}
