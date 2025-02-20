<?php

namespace ParkkTech\FastMagento\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SortingOptions implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'relevance', 'label' => __('Relevance')],
            ['value' => 'price_asc', 'label' => __('Price: Low to High')],
            ['value' => 'price_desc', 'label' => __('Price: High to Low')],
            ['value' => 'newest', 'label' => __('Newest Arrivals')],
            ['value' => 'name_asc', 'label' => __('Name: A to Z')],
            ['value' => 'name_desc', 'label' => __('Name: Z to A')],
        ];
    }
}
