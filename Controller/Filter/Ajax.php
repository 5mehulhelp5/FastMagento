<?php

namespace ParkkTech\FastMagento\Controller\Filter;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use OpenSearch\Client;

class Ajax extends Action
{
    private $jsonFactory;
    private $openSearchClient;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Client $openSearchClient
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->openSearchClient = $openSearchClient;
    }

    public function execute()
    {
        $filters = $this->getRequest()->getParams();
        $query = ['bool' => ['must' => []]];

        foreach ($filters as $attribute => $value) {
            $query['bool']['must'][] = ['match' => [$attribute => $value]];
        }

        $response = $this->openSearchClient->search([
            'index' => 'magento_products',
            'body' => ['query' => $query, 'size' => 20]
        ]);

        $results = [];
        foreach ($response['hits']['hits'] as $hit) {
            $results[] = [
                'name' => $hit['_source']['name'],
                'price' => $hit['_source']['price'],
                'image' => $hit['_source']['image'],
                'url' => $hit['_source']['url_key']
            ];
        }

        return $this->jsonFactory->create()->setData($results);
    }
}
