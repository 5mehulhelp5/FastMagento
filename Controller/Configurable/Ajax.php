<?php

namespace ParkkTech\FastMagento\Controller\Configurable;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use OpenSearch\Client;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

class Ajax extends Action
{
    private $jsonFactory;
    private $openSearchClient;
    private $configurableProductType;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Client $openSearchClient,
        Configurable $configurableProductType
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->openSearchClient = $openSearchClient;
        $this->configurableProductType = $configurableProductType;
    }

    public function execute()
    {
        $options = $this->getRequest()->getParams();
        $query = ['bool' => ['must' => []]];

        foreach ($options as $attribute => $value) {
            $query['bool']['must'][] = ['match' => [$attribute => $value]];
        }

        $response = $this->openSearchClient->search([
            'index' => 'magento_products',
            'body' => ['query' => $query, 'size' => 1]
        ]);

        if (!empty($response['hits']['hits'])) {
            $product = $response['hits']['hits'][0]['_source'];

            return $this->jsonFactory->create()->setData([
                'name' => $product['name'],
                'price' => $product['final_price'],
                'image' => $product['image'],
                'url' => $product['url_key']
            ]);
        }

        return $this->jsonFactory->create()->setData([]);
    }
}
