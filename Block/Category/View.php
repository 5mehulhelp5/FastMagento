<?php

namespace ParkkTech\FastMagento\Block\Category;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Framework\App\RequestInterface;

class View extends Template
{
    private $categoryRepository;
    private $request;

    public function __construct(
        Context            $context,
        CategoryRepository $categoryRepository,
        RequestInterface   $request,
        array              $data = []
    )
    {
        $this->categoryRepository = $categoryRepository;
        $this->request = $request;
        parent::__construct($context, $data);
    }

    public function getCategory()
    {
        $categoryId = $this->request->getParam('id');
        return $this->categoryRepository->get($categoryId);
    }
}
