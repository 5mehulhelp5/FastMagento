<?php

namespace ParkkTech\FastMagento\Block\Search;

use Magento\Framework\View\Element\Template;
use Magento\Framework\UrlInterface;
use OpenSearch\Client;

class Results extends Template
{
    private $openSearchClient;
    private $urlBuilder;

    public function __construct(
        Template\Context $context,
        Client $openSearchClient,
        UrlInterface $urlBuilder,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->openSearchClient = $openSearchClient;
        $this->urlBuilder = $urlBuilder;
    }

    public function getProducts()
    {
        $queryText = $this->getSearchQuery();
        if (!$queryText) {
            return [];
        }

        $pageSize = (int) $this->getRequest()->getParam('limit', 12);
        $currentPage = (int) $this->getRequest()->getParam('p', 1);
        $offset = ($currentPage - 1) * $pageSize;

        $query = [
            'index' => 'magento_products',
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['match' => ['name' => $queryText]]
                        ]
                    ]
                ],
                'size' => $pageSize,
                'from' => $offset,
                'sort' => [['relevance' => 'desc']]
            ]
        ];

        try {
            $response = $this->openSearchClient->search($query);
            return array_map(fn($hit) => $hit['_source'], $response['hits']['hits']);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getPaginationHtml()
    {
        $currentPage = (int) $this->getRequest()->getParam('p', 1);
        $prevPage = $currentPage > 1 ? $currentPage - 1 : 1;
        $nextPage = $currentPage + 1;

        $queryString = '?q=' . urlencode($this->getSearchQuery()) . '&limit=' . $this->getRequest()->getParam('limit', 12);

        return "
            <a href='{$queryString}&p=$prevPage' class='page-prev'>Previous</a>
            <span>Page $currentPage</span>
            <a href='{$queryString}&p=$nextPage' class='page-next'>Next</a>
        ";
    }

    public function getSearchQuery()
    {
        return trim((string) $this->getRequest()->getParam('q', ''));
    }

    public function getProductUrl(array $product): string
    {
        return isset($product['url_key']) ? $this->urlBuilder->getUrl($product['url_key']) : '#';
    }

    public function getProductImage(array $product): string
    {
        return isset($product['image']) ? $product['image'] : $this->getDefaultImage();
    }

    private function getDefaultImage(): string
    {
        return $this->getViewFileUrl('Magento_Catalog/images/product/placeholder/image.jpg');
    }
}
