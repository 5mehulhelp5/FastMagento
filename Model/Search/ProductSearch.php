<?php

namespace ParkkTech\FastMagento\Model\Search;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class ProductSearch
{
    const XML_PATH_PREFIX = 'catalog/search/elasticsearch7_index_prefix';
    private $client;
    private $scopeConfig;

    public function __construct(
        ClientInterface      $client,
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->client = $client;
        $this->scopeConfig = $scopeConfig;
    }

    public function getById($productId)
    {
        $indexName = $this->getIndexName();
        // Query for doc by "entity_id"
        $query = [
            'index' => $indexName,
            'body' => [
                'query' => [
                    'term' => ['entity_id' => $productId]
                ]
            ]
        ];
        $response = $this->client->search($query);
        // Return first match
        if (!empty($response['hits']['hits'])) {
            return $response['hits']['hits'][0]['_source'];
        }
        return null;
    }

    private function getIndexName()
    {
        $prefix = (string)$this->scopeConfig->getValue(self::XML_PATH_PREFIX);
        return $prefix . '_pdp';
    }
}
