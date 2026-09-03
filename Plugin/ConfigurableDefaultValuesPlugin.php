<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\ConfigurableProduct\Model\ConfigurableAttributeData;

/**
 * Drop null entries from a configurable product's `defaultValues`.
 *
 * Core emits one entry per configurable attribute and fills it from the product's preconfigured
 * values, so an attribute that was not preconfigured comes out as `null` rather than being absent.
 * That is harmless while preconfiguration is all-or-nothing, which is how core uses it — a wishlist
 * or reorder always carries every attribute.
 *
 * Partial preconfiguration is legitimate: a caller may preselect one attribute (a colour, say) and
 * leave another to the shopper.
 *
 * So the nulls are removed before they reach the template. Nothing that relied on them changes:
 * core only reads `defaultValues` when preconfigured values exist, and a null there never meant
 * "select this", it meant "nothing was specified".
 */
class ConfigurableDefaultValuesPlugin
{
    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetAttributesData(
        ConfigurableAttributeData $subject,
        array $result
    ): array {
        if (empty($result['defaultValues']) || !is_array($result['defaultValues'])) {
            return $result;
        }

        $result['defaultValues'] = array_filter(
            $result['defaultValues'],
            static fn ($value) => $value !== null && $value !== ''
        );

        return $result;
    }
}
