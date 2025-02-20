<?php

namespace ParkkTech\FastMagento\Controller\Plp;

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
        $filters = $this->getRequest()->getParam('filters', []);
        $sorting = $this->getRequest()->getParam('sorting', 'relevance');
        $page = (int) $this->getRequest()->getParam('p', 1);
        $pageSize = 12;
        $offset = ($page - 1) * $pageSize;

        $query = ['bool' => ['must' => []]];
        foreach ($filters as $attribute => $value) {
            $query['bool']['must'][] = ['match' => [$attribute => $value]];
        }

        $response = $this->openSearchClient->search([
            'index' => 'magento_products',
            'body' => [
                'query' => $query,
                'size' => $pageSize,
                'from' => $offset
            ]
        ]);

        $productsHtml = "";
        foreach ($response['hits']['hits'] as $hit) {
            $productsHtml .= "<div class='product-item'>
                <a href='{$hit['_source']['url_key']}'>
                    <img src='{$hit['_source']['image']}' alt='{$hit['_source']['name']}'>
                    <h2>{$hit['_source']['name']}</h2>
                    <span class='price'>{$hit['_source']['final_price']}</span>
                </a>
            </div>";
        }

        $paginationHtml = "<a href='#' class='page' data-page='" . ($page - 1) . "'>Previous</a>
                           <span>Page $page</span>
                           <a href='#' class='page' data-page='" . ($page + 1) . "'>Next</a>";

        return $this->jsonFactory->create()->setData([
            'products' => $productsHtml,
            'pagination' => $paginationHtml
        ]);
    }
}
