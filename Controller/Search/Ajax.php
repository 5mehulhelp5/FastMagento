<?php

namespace ParkkTech\FastMagento\Controller\Search;

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
        $query = $this->getRequest()->getParam('q');
        $resultJson = $this->jsonFactory->create();

        $response = $this->openSearchClient->search([
            'index' => 'magento_products',
            'body' => [
                'query' => ['match' => ['name' => $query]],
                'size' => 5
            ]
        ]);

        $results = [];
        foreach ($response['hits']['hits'] as $hit) {
            $results[] = [
                'name' => $hit['_source']['name'],
                'url' => $hit['_source']['url_key']
            ];
        }

        return $resultJson->setData($results);
    }
}

