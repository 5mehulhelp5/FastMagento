<?php
namespace ParkkTech\FastMagento\Block\Product;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use ParkkTech\FastMagento\Model\Search\ProductSearch;

class ListProduct extends Template
{
    private $plpSearch;

    public function __construct(
        Context $context,
        ProductSearch $plpSearch,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->plpSearch = $plpSearch;
    }

    public function getProductList()
    {
        // Possibly read category ID from request
        $categoryId = (int) $this->getRequest()->getParam('id');
        // Then query the index for products that have category_id = $categoryId
        return $this->plpSearch->searchByCategory($categoryId);
    }
}
