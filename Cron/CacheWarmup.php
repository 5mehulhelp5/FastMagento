<?php

namespace ParkkTech\FastMagento\Cron;

use OpenSearch\Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class CacheWarmup
{
    private $openSearchClient;
    private $scopeConfig;
    private $logger;

    const XML_PATH_POPULAR_SEARCHES = 'fastmagento/cache/popular_searches';

    public function __construct(
        Client $openSearchClient,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->openSearchClient = $openSearchClient;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function execute()
    {
        $searchTerms = explode(',', $this->scopeConfig->getValue(self::XML_PATH_POPULAR_SEARCHES));

        foreach ($searchTerms as $term) {
            $this->cacheSearchResults(trim($term));
        }

        $this->logger->info('FastMagento Cache Warmup Completed.');
    }

    private function cacheSearchResults($queryText)
    {
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
                'size' => 50
            ]
        ];

        $response = $this->openSearchClient->search($query);
        // The query is executed, warming up the cache.
    }
}
