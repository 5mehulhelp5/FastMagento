<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Review;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Data\CollectionFactory as DataCollectionFactory;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\Review;
use Magento\Store\Model\ScopeInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use Psr\Log\LoggerInterface;

/**
 * Serves the product page's review list from the review index instead of MySQL.
 *
 * The native path for one page of reviews is: a COUNT for the pager, the review SELECT, then
 * one rating_option_vote query PER REVIEW (Collection::addRateVotes). This plugin answers the
 * whole thing with one OpenSearch search — the votes are stored inside each review document —
 * and reports the total from the same response, so the pager runs no COUNT either.
 *
 * Only the exact shape Magento\Review\Block\Product\View builds is served: store filter +
 * approved status + product entity filter + date order, page-sized. Anything else (admin
 * grids, customer account lists, custom filters) falls through to the native query untouched.
 */
class ReviewListFromIndexPlugin
{
    public const XML_PATH_ENABLED = 'fastmagento/serving/serve_reviews';

    private const FLAG_SERVED = 'fastmagento_review_served';
    private const KEY_ENTITY = 'fastmagento_rv_entity';
    private const KEY_STATUS = 'fastmagento_rv_status';
    private const KEY_STORE = 'fastmagento_rv_store';
    private const KEY_ORDER = 'fastmagento_rv_order';
    private const KEY_TOTAL = 'fastmagento_rv_total';

    /** A list with no page size set is still bounded: nobody renders more than this in one page. */
    private const MAX_UNPAGED = 200;

    /** Filter fields the product-page collection sets; any other filter means "not ours". */
    private const KNOWN_FILTERS = ['entity', 'entity_pk_value', 'status'];

    private ?object $client = null;

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DataCollectionFactory $dataCollectionFactory,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    // ---- record what the block asked for (the filters are only rendered into SQL at load time).
    // Collections are not DataObjects; flags are the collection-level scratch space.

    public function afterAddEntityFilter(Collection $subject, $result, $entity, $pkValue)
    {
        $subject->setFlag(self::KEY_ENTITY, [$entity, $pkValue]);
        return $result;
    }

    public function afterAddStatusFilter(Collection $subject, $result, $status)
    {
        $subject->setFlag(self::KEY_STATUS, $status);
        return $result;
    }

    public function afterAddStoreFilter(Collection $subject, $result, $storeId)
    {
        $subject->setFlag(self::KEY_STORE, $storeId);
        return $result;
    }

    public function afterSetDateOrder(Collection $subject, $result, $dir = 'DESC')
    {
        $subject->setFlag(self::KEY_ORDER, strtoupper((string) $dir) === 'ASC' ? 'asc' : 'desc');
        return $result;
    }

    // ---- serve

    public function aroundLoad(Collection $subject, callable $proceed, $printQuery = false, $logQuery = false)
    {
        if ($subject->isLoaded() || !$this->isEligible($subject)) {
            return $proceed($printQuery, $logQuery);
        }
        $page = $this->fetch($subject);
        if ($page === null) {
            return $proceed($printQuery, $logQuery);
        }
        [$hits, $total] = $page;
        foreach ($hits as $hit) {
            $subject->addItem($this->toReview($subject, $hit['_source'] ?? []));
        }
        $this->markLoaded($subject, $total);
        $subject->setFlag(self::FLAG_SERVED, true);
        $subject->setFlag(self::KEY_TOTAL, $total);
        return $subject;
    }

    /** Votes came with the documents; the per-review vote queries have nothing left to do. */
    public function aroundAddRateVotes(Collection $subject, callable $proceed)
    {
        return $subject->getFlag(self::FLAG_SERVED) ? $subject : $proceed();
    }

    /** The pager's total comes from the search response instead of a COUNT. */
    public function aroundGetSize(Collection $subject, callable $proceed)
    {
        if (!$subject->isLoaded() && $this->isEligible($subject)) {
            $subject->load();
        }
        if ($subject->getFlag(self::FLAG_SERVED)) {
            return (int) $subject->getFlag(self::KEY_TOTAL);
        }
        return $proceed();
    }

    private function isEligible(Collection $subject): bool
    {
        try {
            if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
                return false;
            }
            [$entity, $pk] = $subject->getFlag(self::KEY_ENTITY) ?: [null, null];
            if ($entity !== 'product' || (int) $pk <= 0) {
                return false;
            }
            $status = $subject->getFlag(self::KEY_STATUS);
            if ($status !== Review::STATUS_APPROVED && $status !== 'approved' && (string) $status !== (string) Review::STATUS_APPROVED) {
                return false;
            }
            if ($subject->getFlag(self::KEY_STORE) === null) {
                return false;
            }
            // Any filter beyond the three the product page sets, or any WHERE the store join did
            // not put there, is a shape this index does not model.
            $filters = (function () {
                return $this->_filters;
            })->call($subject);
            foreach ($filters as $filter) {
                if (!in_array($filter['field'] ?? '', self::KNOWN_FILTERS, true)) {
                    return false;
                }
            }
            foreach ((array) $subject->getSelect()->getPart(\Magento\Framework\DB\Select::WHERE) as $where) {
                if (strpos((string) $where, 'store.store_id') === false) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: int}|null null = cannot answer, use MySQL
     */
    private function fetch(Collection $subject): ?array
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return null;
            }
            [, $productId] = $subject->getFlag(self::KEY_ENTITY);
            $stores = array_map('intval', (array) $subject->getFlag(self::KEY_STORE));
            $pageSize = (int) $subject->getPageSize();
            $size = $pageSize > 0 ? min($pageSize, self::MAX_UNPAGED) : self::MAX_UNPAGED;
            $page = max(1, (int) $subject->getCurPage());
            $dir = $subject->getFlag(self::KEY_ORDER) ?: 'desc';

            $resp = $client->search([
                'index' => $this->openSearchConfig->getReviewIndexName(),
                'body' => [
                    'from' => ($page - 1) * $size,
                    'size' => $size,
                    'track_total_hits' => true,
                    'query' => ['bool' => ['filter' => [
                        ['term' => ['product_id' => (int) $productId]],
                        ['term' => ['status_id' => Review::STATUS_APPROVED]],
                        ['terms' => ['store_ids' => $stores]],
                    ]]],
                    'sort' => [['created_at' => $dir], ['review_id' => $dir]],
                ],
            ]);
            $total = $resp['hits']['total'];
            $total = is_array($total) ? (int) ($total['value'] ?? 0) : (int) $total;
            return [$resp['hits']['hits'] ?? [], $total];
        } catch (\Throwable $e) {
            // Index missing or cluster unreachable: the native query still works.
            $this->logger->debug('[FastMagento] review list from index unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string, mixed> $src
     */
    private function toReview(Collection $subject, array $src): Review
    {
        /** @var Review $review */
        $review = $subject->getNewEmptyItem();
        $review->setData([
            'review_id' => (int) ($src['review_id'] ?? 0),
            'created_at' => (string) ($src['created_at'] ?? ''),
            'entity_pk_value' => (int) ($src['product_id'] ?? 0),
            'status_id' => (int) ($src['status_id'] ?? Review::STATUS_APPROVED),
            'title' => (string) ($src['title'] ?? ''),
            'detail' => (string) ($src['detail'] ?? ''),
            'nickname' => (string) ($src['nickname'] ?? ''),
            'customer_id' => $src['customer_id'] ?? null,
            'stores' => $src['store_ids'] ?? [],
        ]);
        /** @var DataCollection $votes */
        $votes = $this->dataCollectionFactory->create();
        foreach ($src['ratings'] ?? [] as $vote) {
            $votes->addItem($this->dataObjectFactory->create(['data' => [
                'vote_id' => (int) ($vote['rating_id'] ?? 0),
                'review_id' => (int) ($src['review_id'] ?? 0),
                'rating_id' => (int) ($vote['rating_id'] ?? 0),
                'option_id' => (int) ($vote['option_id'] ?? 0),
                'rating_code' => (string) ($vote['rating_code'] ?? ''),
                'percent' => (int) ($vote['percent'] ?? 0),
                'value' => (int) ($vote['value'] ?? 0),
                'position' => (int) ($vote['position'] ?? 0),
            ]]));
        }
        $review->setRatingVotes($votes);
        return $review;
    }

    /** Both are protected on the collection, hence the bound closure (same as LinkProductCollectionPlugin). */
    private function markLoaded(Collection $subject, int $total): void
    {
        (function () use ($total) {
            $this->_setIsLoaded(true);
            $this->_totalRecords = $total;
        })->call($subject);
    }

    private function getClient(): ?object
    {
        if ($this->client === null) {
            $this->client = $this->clientResolver
                ->create($this->engineResolver->getCurrentSearchEngine())
                ->getOpenSearchClient();
        }
        return $this->client;
    }
}
