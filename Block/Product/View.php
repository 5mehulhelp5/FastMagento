<?php

namespace ParkkTech\FastMagento\Block\Product;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;

class View extends Template
{
    protected $registry;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
    }

    /**
     * Get Product Data from OpenSearch (Registered in Controller)
     */
    public function getProduct()
    {
        return $this->registry->registry('current_product');
    }
}
