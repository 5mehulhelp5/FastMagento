<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Swatches;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use ParkkTech\FastMagento\Model\OptionDictionary;

/**
 * Swatch definitions (type + colour/image/text value per option) from the attribute-option
 * dictionary instead of eav_attribute_option_swatch. Layered navigation and every swatch
 * renderer ask for them by option id; the dictionary documents now carry each option's
 * swatch, so one lookup in the index answers a whole page's worth. An option the dictionary
 * does not know (index behind an attribute save) hands the whole call back to the query.
 */
class SwatchesFromIndexPlugin
{
    public const XML_PATH_ENABLED = 'fastmagento/serving/serve_swatches';

    public function __construct(
        private readonly OptionDictionary $dictionary,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function aroundGetSwatchesByOptionsId(SwatchHelper $subject, callable $proceed, array $optionIds)
    {
        try {
            if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
                return $proceed($optionIds);
            }
            $swatches = $this->dictionary->getSwatchesByOptionIds(array_map('intval', $optionIds));
            return $swatches ?? $proceed($optionIds);
        } catch (\Throwable $e) {
            return $proceed($optionIds);
        }
    }
}
