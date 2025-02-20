<?php

namespace ParkkTech\FastMagento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OpenSearch\Client;
use Magento\Catalog\Api\ProductRepositoryInterface;

class ProductSave implements ObserverInterface
{
    private $openSearchClient;
    private $productRepository;

    public function __construct(
        Client $openSearchClient,
        ProductRepositoryInterface $productRepository
    ) {
        $this->openSearchClient = $openSearchClient;
        $this->productRepository = $productRepository;
    }

    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();
        $this->openSearchClient->index([
            'index' => 'magento_products',
            'id' => $product->getId(),
            'body' => [
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'special_price' => $product->getSpecialPrice(),
                'final_price' => $product->getFinalPrice(),
                'image' => $product->getImage(),
                'url_key' => $product->getUrlKey(),
                'custom_attributes' => $this->getCustomAttributes($product)
            ]
        ]);
    }

    private function getCustomAttributes($product)
    {
        $customAttributes = [];
        foreach ($product->getCustomAttributes() as $attribute) {
            $customAttributes[$attribute->getAttributeCode()] = $attribute->getValue();
        }
        return $customAttributes;
    }
}
