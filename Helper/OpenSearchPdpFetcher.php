<?php
namespace ParkkTech\FastMagento\Helper;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use Psr\Log\LoggerInterface;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;

/**
 * A simple helper to do $searchClient->getOpenSearchClient()->get(...) by doc ID.
 */
class OpenSearchPdpFetcher
{
    private $clientResolver;
    private $engineResolver;
    private $logger;
    private $productIndexer;

    /**
     * Per-request memo of docs already fetched, entity_id => _source|null (null = known miss).
     * A PDP render asks for the same product from several plugins (repository, parent resolver,
     * link collection) and each ask was a fresh round-trip; the doc cannot change mid-request.
     *
     * @var array<int, array|null>
     */
    private array $memo = [];

    public function __construct(
        ClientResolver $clientResolver,
        EngineResolverInterface $engineResolver,
        LoggerInterface $logger,
        ProductIndexer $productIndexer
    ) {
        $this->clientResolver = $clientResolver;
        $this->engineResolver = $engineResolver;
        $this->logger = $logger;
        $this->productIndexer = $productIndexer;
    }

    /**
     * Batch fetch docs by id (single mget). Returns [entity_id => _source] for docs found.
     *
     * @param int[] $ids
     * @return array<int, array>
     */
    public function fetchByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        if (!$ids) {
            return $out;
        }
        $missing = [];
        foreach ($ids as $id) {
            if (array_key_exists($id, $this->memo)) {
                if ($this->memo[$id] !== null) {
                    $out[$id] = $this->memo[$id];
                }
            } else {
                $missing[] = $id;
            }
        }
        if (!$missing) {
            return $out;
        }
        $ids = $missing;
        try {
            $engine = $this->engineResolver->getCurrentSearchEngine();
            $searchClient = $this->clientResolver->create($engine);
            $indexName = $this->productIndexer->getIndexName();
            $resp = $searchClient->getOpenSearchClient()->mget([
                'index' => $indexName,
                'body' => ['ids' => array_map('strval', $ids)],
            ]);
            foreach ($resp['docs'] ?? [] as $doc) {
                $docId = (int) ($doc['_id'] ?? 0);
                if (!empty($doc['found']) && isset($doc['_source'])) {
                    $out[$docId] = $doc['_source'];
                    $this->memo[$docId] = $doc['_source'];
                } elseif ($docId) {
                    $this->memo[$docId] = null;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('OpenSearchPdpFetcher mget error: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Fetch doc by $id from the OS index. Return the _source or null if not found.
     */
    /**
     * @param int $id
     * @return array|null
     */
    public function fetchPdpById(int $id): ?array
    {
        if (array_key_exists($id, $this->memo)) {
            return $this->memo[$id];
        }
        try {
            // 1) get the engine code from admin config
            $engine = $this->engineResolver->getCurrentSearchEngine();
            // 2) build the client
            $searchClient = $this->clientResolver->create($engine);
            // 3) get the same index name from ProductIndexer
            $indexName = $this->productIndexer->getIndexName();

            $params = [
                'index' => $indexName,
                'id'    => (string)$id
            ];
            $resp = $searchClient->getOpenSearchClient()->get($params);
            if (isset($resp['_source'])) {
                $this->memo[$id] = $resp['_source'];
                return $resp['_source'];
            }
            $this->memo[$id] = null; // a confirmed miss is memoised too; an exception is not (transient)
        } catch (\Exception $e) {
            $this->logger->error('OpenSearchPdpFetcher error: ' . $e->getMessage());
        }
        return null;
    }
}
