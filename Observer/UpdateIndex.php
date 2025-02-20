<?php

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OpenSearch\Client;

class UpdateIndex implements ObserverInterface
{
    private $openSearchClient;

    public function __construct(Client $openSearchClient)
    {
        $this->openSearchClient = $openSearchClient;
    }

    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();
        $productData = $product->getData();

        $this->openSearchClient->index([
            'index' => 'magento_products',
            'id'    => $product->getId(),
            'body'  => $productData
        ]);
    }
}
