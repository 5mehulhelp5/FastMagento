<?php

namespace ParkkTech\FastMagento\Controller\Product;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Category extends Action
{
    use \ParkkTech\FastMagento\Model\ServingGateAware;

    private $resultPageFactory;

    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        if (!$this->isServingEnabled()) {
            // Master switch off: this endpoint doesn't exist in stock Magento.
            return $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_FORWARD)
                ->forward('noroute');
        }
        return $this->resultPageFactory->create();
    }
}
