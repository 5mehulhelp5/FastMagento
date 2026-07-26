<?php

namespace ParkkTech\FastMagento\Model\Adapter;

use Magento\Framework\Search\AdapterInterface;
use Magento\Framework\Search\RequestInterface;
use Magento\Framework\Search\Response\QueryResponse;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Framework\App\Config\ScopeConfigInterface;

class OpenSearchAdapter implements AdapterInterface
{
    private $connectionManager;
    private $scopeConfig;

    const XML_PATH_SEARCH_ATTRIBUTES = 'catalog/search/search_attributes';
    const XML_PATH_SORTING_PRIORITIES = 'catalog/search/sorting_priorities';
    const XML_PATH_INDEX_PREFIX = 'catalog/search/elasticsearch_index_prefix';

    public function __construct(
        ConnectionManager $connectionManager,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->connectionManager = $connectionManager;
        $this->scopeConfig = $scopeConfig;
    }

    public function query(RequestInterface $request): QueryResponse
    {
        $queryText = $request->getQueryText();
        $searchAttributes = explode(',', (string) $this->scopeConfig->getValue(self::XML_PATH_SEARCH_ATTRIBUTES));
        $sortingPriorities = explode(',', (string) $this->scopeConfig->getValue(self::XML_PATH_SORTING_PRIORITIES));
        $indexPrefix = (string) $this->scopeConfig->getValue(self::XML_PATH_INDEX_PREFIX);

        // Use Magento's connection manager to get the OpenSearch connection
        $connection = $this->connectionManager->getConnection();
        if (!$connection) {
            throw new \Exception('Failed to retrieve OpenSearch connection.');
        }

        $query = [
            'index' => $indexPrefix . '_products',
            'body' => [
                'query' => [
                    'bool' => [
                        'should' => []
                    ]
                ],
                'size' => 50,
                'sort' => $this->buildSortQuery($sortingPriorities)
            ]
        ];

        foreach ($searchAttributes as $attribute) {
            $query['body']['query']['bool']['should'][] = [
                'match' => [$attribute => $queryText]
            ];
        }

        // Instead of getClient(), use OpenSearch connection directly
        $response = $connection->search($query);
        $documents = [];

        foreach ($response['hits']['hits'] as $hit) {
            $documents[] = [
                'id' => $hit['_id'],
                'score' => $hit['_score'],
                'source' => $hit['_source']
            ];
        }

        return new QueryResponse($documents, $response['hits']['total']['value'] ?? 0);
    }

    private function buildSortQuery(array $sortingPriorities)
    {
        $sortQuery = [];
        foreach ($sortingPriorities as $sortField) {
            $sortQuery[] = [$sortField => 'desc'];
        }
        return $sortQuery;
    }
}
