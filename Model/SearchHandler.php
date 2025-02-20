<?php

namespace ParkkTech\FastMagento\Model;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Search\Model\EngineResolver;

class SearchHandler
{
    private ClientInterface $client;
    private EngineResolver $engineResolver;

    public function __construct(ClientInterface $client, EngineResolver $engineResolver)
    {
        $this->client = $client;
        $this->engineResolver = $engineResolver;
    }

    public function search(array $query)
    {
        // Ensure OpenSearch is the active engine
        if ($this->engineResolver->getCurrentSearchEngine() !== 'opensearch') {
            throw new \Exception("OpenSearch is not enabled in Magento settings.");
        }

        return $this->client->search($query);
    }
}
