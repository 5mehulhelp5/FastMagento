<?php

namespace ParkkTech\FastMagento\SearchAdapter\Client;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class OpenSearchClient implements ClientInterface
{
    private ScopeConfigInterface $scopeConfig;
    private ClientInterface $client;

    public function __construct(ScopeConfigInterface $scopeConfig, ClientInterface $client)
    {
        $this->scopeConfig = $scopeConfig;
        $this->client = $client;
    }

    public function testConnection()
    {
        return $this->client->testConnection();
    }

    public function search(array $query)
    {
        return $this->client->search($query);
    }
}
