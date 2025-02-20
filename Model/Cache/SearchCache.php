<?php

namespace ParkkTech\FastMagento\Model\Cache;

use Magento\Framework\Cache\FrontendInterface;
use OpenSearch\Client;

class SearchCache
{
    private $cache;
    private $openSearchClient;

    public function __construct(FrontendInterface $cache, Client $openSearchClient)
    {
        $this->cache = $cache;
        $this->openSearchClient = $openSearchClient;
    }

    public function getCachedResults($query)
    {
        $cacheKey = 'fastmagento_search_' . md5($query);
        $cachedData = $this->cache->load($cacheKey);

        if ($cachedData) {
            return unserialize($cachedData);
        }

        $response = $this->openSearchClient->search([
            'index' => 'magento_products',
            'body' => [
                'query' => ['match' => ['name' => $query]],
                'size' => 10
            ]
        ]);

        $results = [];
        foreach ($response['hits']['hits'] as $hit) {
            $results[] = $hit['_source'];
        }

        $this->cache->save(serialize($results), $cacheKey, ['fastmagento_search'], 3600);
        return $results;
    }
}
