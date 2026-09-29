<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Facet heading for an attribute code: the attribute's storefront label for the current store
 * (e.g. seller_id -> "Seller"), as the native layered navigation shows it.
 *
 * Attribute metadata comes from the EAV config, which is cached, so this adds no per-request
 * queries once warm. Falls back to a readable form of the code ("shock_spacing" ->
 * "Shock Spacing") when the attribute has no label or can't be resolved.
 */
class FacetLabelResolver
{
    /**
     * @var array<string, string> "storeId:code" => label, per request
     */
    private array $memo = [];

    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getLabel(string $code): string
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            $storeId = 0;
        }
        return $this->memo[$storeId . ':' . $code] ??= $this->resolve($code, $storeId);
    }

    private function resolve(string $code, int $storeId): string
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            $label = $attribute && $attribute->getId() ? trim((string) $attribute->getStoreLabel($storeId)) : '';
            if ($label !== '') {
                return $label;
            }
        } catch (\Throwable $e) {
            // unknown attribute: fall through to the readable code
        }
        return ucwords(str_replace('_', ' ', $code));
    }
}
