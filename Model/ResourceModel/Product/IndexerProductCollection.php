<?php

namespace ParkkTech\FastMagento\Model\ResourceModel\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection as MagentoProductCollection;

/**
 * Custom product collection for indexing (bypasses OpenSearch).
 */
class IndexerProductCollection extends MagentoProductCollection
{
    /**
     * Override constructor to ensure only MySQL queries (bypass OpenSearch).
     */
    protected function _construct()
    {
        parent::_construct();
    }
}
