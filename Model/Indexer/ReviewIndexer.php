<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Indexer;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Review\Model\Review;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Model\OpenSearch\IndexSettings;

/**
 * Projects approved product reviews, with their rating votes, into their own OpenSearch index
 * (one document per review), and keeps the product documents' reviews_count / rating_summary
 * current whenever a review changes.
 *
 * Why a separate index rather than reviews nested in the product document: a product document
 * is rebuilt whenever anything about the product changes, and on a large catalogue a popular
 * product can carry thousands of reviews. Nesting them would make every product reprojection
 * carry the review payload, and every review save reproject the whole product. One document per
 * review keeps both writes O(1) and lets the product page ask for exactly one page of reviews.
 *
 * Built for scale: the full build streams the review table by keyset (review_id > last) in
 * fixed chunks and never instantiates a model, so memory is flat regardless of review count;
 * partial runs touch only the changed reviews and only the affected products' two summary
 * fields (a partial `update`, not a product reprojection).
 */
class ReviewIndexer implements ActionInterface, MviewActionInterface
{
    /** Reviews per SQL page and per bulk body. Documents are small; 2000 keeps a body well under 5 MB. */
    private const CHUNK = 2000;

    /** review_entity.entity_code for products. */
    private const ENTITY_PRODUCT = 'product';

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly IndexSettings $indexSettings,
        private readonly WriteLog $writeLog
    ) {
    }

    public function executeFull(): void
    {
        $indexName = $this->getIndexName();
        try {
            $client = $this->getSearchClient();
            if ($client->indexExists($indexName)) {
                $client->deleteIndex($indexName);
            }
            $client->createIndex($indexName, $this->buildMapping());
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] review index (re)create failed: ' . $e->getMessage());
            return;
        }
        $this->run(null);
        try {
            $client->getOpenSearchClient()->indices()->refresh(['index' => $indexName]);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] review index refresh failed: ' . $e->getMessage());
        }
    }

    public function executeList(array $ids): void
    {
        $this->run(array_map('intval', $ids));
    }

    public function executeRow($id): void
    {
        $this->run([(int) $id]);
    }

    /**
     * Mview entry point: $ids are review_ids from the changelog of any subscribed table.
     *
     * @param int[] $ids
     */
    public function execute($ids): void
    {
        $this->run(array_map('intval', (array) $ids));
    }

    public function getIndexName(): string
    {
        return $this->openSearchConfig->getReviewIndexName();
    }

    /**
     * @param int[]|null $ids null = every approved review (full build), otherwise exactly these
     */
    private function run(?array $ids): void
    {
        try {
            $client = $this->getSearchClient()->getOpenSearchClient();
            $indexName = $this->getIndexName();
            $storeId = $this->getIndexStoreId();
            $conn = $this->resource->getConnection();
            $entityId = $this->productEntityId();

            $affectedProducts = [];
            if ($ids === null) {
                $lastId = 0;
                do {
                    $rows = $this->fetchReviews($conn, $entityId, $lastId, null);
                    if (!$rows) {
                        break;
                    }
                    $this->bulk($client, $this->docsFor($conn, $rows, $storeId), $indexName);
                    $lastId = (int) end($rows)['review_id'];
                } while (count($rows) === self::CHUNK);
                return;
            }

            $ids = array_values(array_unique(array_filter($ids)));
            if (!$ids) {
                return;
            }
            // Reviews being deleted or unapproved must leave the index; find out which products
            // they belonged to BEFORE the documents are gone, so their summaries get refreshed.
            $affectedProducts = $this->productsOfIndexedReviews($client, $indexName, $ids);
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $rows = $this->fetchReviews($conn, $entityId, 0, $chunk);
                $found = [];
                $lines = '';
                foreach ($rows as $row) {
                    $found[(int) $row['review_id']] = true;
                    $affectedProducts[(int) $row['entity_pk_value']] = true;
                }
                $approved = array_filter($rows, static fn ($r) => (int) $r['status_id'] === Review::STATUS_APPROVED);
                foreach ($this->docsFor($conn, $approved, $storeId) as $id => $doc) {
                    $lines .= json_encode(['index' => ['_index' => $indexName, '_id' => (string) $id]]) . "\n"
                        . json_encode($doc) . "\n";
                }
                foreach ($chunk as $id) {
                    if (!isset($found[$id]) || (int) ($rows[$id]['status_id'] ?? 0) !== Review::STATUS_APPROVED) {
                        $lines .= json_encode(['delete' => ['_index' => $indexName, '_id' => (string) $id]]) . "\n";
                    }
                }
                $this->send($client, $lines, 'review');
            }
            $this->refreshProductSummaries($client, $conn, array_keys($affectedProducts), $storeId);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] review indexer error: ' . $e->getMessage());
        }
    }

    /**
     * One page of reviews joined to their detail row, keyed by review_id, in review_id order.
     *
     * @param int[]|null $onlyIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchReviews($conn, int $entityId, int $afterId, ?array $onlyIds): array
    {
        $select = $conn->select()
            ->from(
                ['r' => $this->resource->getTableName('review')],
                ['review_id', 'created_at', 'entity_pk_value', 'status_id']
            )
            ->joinLeft(
                ['d' => $this->resource->getTableName('review_detail')],
                'd.review_id = r.review_id',
                ['title', 'detail', 'nickname', 'customer_id']
            )
            ->where('r.entity_id = ?', $entityId)
            ->order('r.review_id ASC');
        if ($onlyIds !== null) {
            $select->where('r.review_id IN (?)', $onlyIds);
        } else {
            $select->where('r.status_id = ?', Review::STATUS_APPROVED)
                ->where('r.review_id > ?', $afterId)
                ->limit(self::CHUNK);
        }
        $out = [];
        foreach ($conn->fetchAll($select) as $row) {
            $out[(int) $row['review_id']] = $row;
        }
        return $out;
    }

    /**
     * Documents for a page of review rows: two set-based queries (stores, votes) per page,
     * never one per review.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>> review_id => document
     */
    private function docsFor($conn, array $rows, int $storeId): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_keys($rows);

        $stores = [];
        $storeSelect = $conn->select()
            ->from($this->resource->getTableName('review_store'), ['review_id', 'store_id'])
            ->where('review_id IN (?)', $ids);
        foreach ($conn->fetchAll($storeSelect) as $s) {
            $stores[(int) $s['review_id']][] = (int) $s['store_id'];
        }

        $votes = [];
        $voteSelect = $conn->select()
            ->from(
                ['v' => $this->resource->getTableName('rating_option_vote')],
                ['vote_id', 'review_id', 'rating_id', 'option_id', 'percent', 'value']
            )
            ->join(
                ['rt' => $this->resource->getTableName('rating')],
                'rt.rating_id = v.rating_id',
                ['position', 'default_code' => 'rating_code']
            )
            ->joinLeft(
                ['t' => $this->resource->getTableName('rating_title')],
                't.rating_id = v.rating_id AND t.store_id = ' . $storeId,
                ['store_code' => 'value']
            )
            ->where('v.review_id IN (?)', $ids)
            ->order('rt.position ASC')
            ->order('v.rating_id ASC');
        foreach ($conn->fetchAll($voteSelect) as $v) {
            $votes[(int) $v['review_id']][] = [
                'vote_id' => (int) $v['vote_id'],
                'rating_id' => (int) $v['rating_id'],
                'option_id' => (int) $v['option_id'],
                'rating_code' => (string) ($v['store_code'] ?? $v['default_code']),
                'percent' => (int) $v['percent'],
                'value' => (int) $v['value'],
                'position' => (int) $v['position'],
            ];
        }

        $docs = [];
        foreach ($rows as $id => $row) {
            $docs[$id] = [
                'review_id' => $id,
                'product_id' => (int) $row['entity_pk_value'],
                'status_id' => (int) $row['status_id'],
                'store_ids' => $stores[$id] ?? [],
                'customer_id' => $row['customer_id'] === null ? null : (int) $row['customer_id'],
                'nickname' => (string) ($row['nickname'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'detail' => (string) ($row['detail'] ?? ''),
                'created_at' => (string) $row['created_at'],
                'ratings' => $votes[$id] ?? [],
            ];
        }
        return $docs;
    }

    /**
     * @param array<int, array<string, mixed>> $docs
     */
    private function bulk($client, array $docs, string $indexName): void
    {
        $lines = '';
        foreach ($docs as $id => $doc) {
            $lines .= json_encode(['index' => ['_index' => $indexName, '_id' => (string) $id]]) . "\n"
                . json_encode($doc) . "\n";
        }
        $this->send($client, $lines, 'review');
    }

    private function send($client, string $lines, string $what): void
    {
        if ($lines === '') {
            return;
        }
        $resp = $client->bulk(['body' => $lines]);
        if (empty($resp['errors'])) {
            return;
        }
        $reasons = [];
        foreach ($resp['items'] ?? [] as $item) {
            $op = reset($item);
            $type = $op['error']['type'] ?? null;
            // A product that is not (yet) in the product index has no summary to update, and a
            // review that was never indexed has nothing to delete. Neither is an error.
            if ($type === null || $type === 'document_missing_exception' || (int) ($op['status'] ?? 0) === 404) {
                continue;
            }
            $reasons[$type . ': ' . ($op['error']['reason'] ?? '')] = true;
        }
        if ($reasons) {
            $this->writeLog->writeErrorLog(sprintf(
                '[FastMagento] OpenSearch rejected %s document(s): %s',
                $what,
                implode('; ', array_slice(array_keys($reasons), 0, 3))
            ));
        }
    }

    /**
     * Which products do these (possibly about-to-be-deleted) review documents belong to.
     *
     * @param int[] $ids
     * @return array<int, true>
     */
    private function productsOfIndexedReviews($client, string $indexName, array $ids): array
    {
        $out = [];
        try {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $resp = $client->mget([
                    'index' => $indexName,
                    'body' => ['ids' => array_map('strval', $chunk)],
                    '_source' => ['product_id'],
                ]);
                foreach ($resp['docs'] ?? [] as $doc) {
                    if (!empty($doc['found']) && isset($doc['_source']['product_id'])) {
                        $out[(int) $doc['_source']['product_id']] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Index missing (first run before a full build): nothing indexed, nothing to map.
        }
        return $out;
    }

    /**
     * Push the two summary fields the product documents already carry (reviews_count,
     * rating_summary — see ProductIndexer::batchReviewSummary) for just the affected products,
     * as a partial update. Magento maintains review_entity_summary on every review save/delete
     * (Review::aggregate), so this is a read of the already-aggregated numbers, not a recount.
     *
     * @param int[] $productIds
     */
    private function refreshProductSummaries($client, $conn, array $productIds, int $storeId): void
    {
        if (!$productIds) {
            return;
        }
        $productIndex = $this->openSearchConfig->getIndexName();
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            $summary = [];
            $select = $conn->select()
                ->from(
                    $this->resource->getTableName('review_entity_summary'),
                    ['entity_pk_value', 'reviews_count', 'rating_summary']
                )
                ->where('entity_pk_value IN (?)', $chunk)
                ->where('store_id = ?', $storeId)
                ->where('entity_type = ?', 1);
            foreach ($conn->fetchAll($select) as $row) {
                $summary[(int) $row['entity_pk_value']] = [
                    'reviews_count' => (int) $row['reviews_count'],
                    'rating_summary' => (int) $row['rating_summary'],
                ];
            }
            $lines = '';
            foreach ($chunk as $pid) {
                $lines .= json_encode(['update' => ['_index' => $productIndex, '_id' => (string) $pid]]) . "\n"
                    . json_encode(['doc' => $summary[$pid] ?? ['reviews_count' => 0, 'rating_summary' => 0]]) . "\n";
            }
            $this->send($client, $lines, 'product summary');
        }
    }

    private function productEntityId(): int
    {
        $conn = $this->resource->getConnection();
        return (int) $conn->fetchOne(
            $conn->select()
                ->from($this->resource->getTableName('review_entity'), 'entity_id')
                ->where('entity_code = ?', self::ENTITY_PRODUCT)
        );
    }

    private function getIndexStoreId(): int
    {
        try {
            return (int) $this->storeManager->getDefaultStoreView()->getId();
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private function getSearchClient()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMapping(): array
    {
        return $this->indexSettings->applyTo([
            'settings' => [
                'analysis' => ['analyzer' => ['default' => ['type' => 'standard']]],
            ],
            'mappings' => [
                'dynamic' => false,
                'properties' => [
                    'review_id' => ['type' => 'integer'],
                    'product_id' => ['type' => 'integer'],
                    'status_id' => ['type' => 'integer'],
                    'store_ids' => ['type' => 'integer'],
                    'customer_id' => ['type' => 'integer'],
                    'nickname' => ['type' => 'keyword', 'ignore_above' => 256],
                    'title' => ['type' => 'text'],
                    'detail' => ['type' => 'text'],
                    'created_at' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
                    'ratings' => [
                        'type' => 'object',
                        'properties' => [
                            'vote_id' => ['type' => 'integer'],
                            'rating_id' => ['type' => 'integer'],
                            'option_id' => ['type' => 'integer'],
                            'rating_code' => ['type' => 'keyword'],
                            'percent' => ['type' => 'integer'],
                            'value' => ['type' => 'integer'],
                            'position' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
