<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Review;

use Magento\Framework\App\Cache\Type\Block as BlockCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Data\CollectionFactory as DataCollectionFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Review\Block\Form as ReviewForm;
use Magento\Review\Model\Rating\OptionFactory;
use Magento\Review\Model\RatingFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The review form's rating set (the "Rating: 1..5 stars" rows) from the cache instead of
 * three queries per product page (ratings + count, options).
 *
 * The set is global per store and changes only when an admin edits a rating, so it is cached
 * under its own tag and cleaned by RatingsCacheInvalidationPlugin on rating save/delete. The
 * cached shape is the models' raw data, rehydrated into Rating / Rating\Option models so the
 * template sees exactly what the native collection would have given it.
 *
 * Also memoised per request: the form template calls getRatings() several times per render.
 */
class RatingsCachePlugin
{
    public const CACHE_TAG = 'FASTMAGENTO_REVIEW_RATINGS';
    private const CACHE_KEY = 'FASTMAGENTO_REVIEW_RATINGS_';
    private const LIFETIME = 86400;

    private ?iterable $ratings = null;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly StoreManagerInterface $storeManager,
        private readonly RatingFactory $ratingFactory,
        private readonly OptionFactory $optionFactory,
        private readonly DataCollectionFactory $dataCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function aroundGetRatings(ReviewForm $subject, callable $proceed)
    {
        if ($this->ratings !== null) {
            return $this->ratings;
        }
        $key = null;
        try {
            $key = self::CACHE_KEY . (int) $this->storeManager->getStore()->getId();
            $raw = $this->cache->load($key);
            if ($raw) {
                $this->ratings = $this->hydrate($this->serializer->unserialize($raw));
                return $this->ratings;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[FastMagento] review ratings cache read failed: ' . $e->getMessage());
        }

        $native = $proceed();
        if ($key !== null) {
            try {
                $this->cache->save(
                    $this->serializer->serialize($this->dehydrate($native)),
                    $key,
                    [self::CACHE_TAG, BlockCache::CACHE_TAG],
                    self::LIFETIME
                );
            } catch (\Throwable $e) {
                $this->logger->debug('[FastMagento] review ratings cache write failed: ' . $e->getMessage());
            }
        }
        $this->ratings = $native;
        return $native;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dehydrate(iterable $ratings): array
    {
        $out = [];
        foreach ($ratings as $rating) {
            $data = $rating->getData();
            $options = [];
            foreach ((array) $rating->getOptions() as $option) {
                $options[] = $option->getData();
            }
            $data['options'] = $options;
            $out[] = $data;
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function hydrate(array $rows): DataCollection
    {
        /** @var DataCollection $collection */
        $collection = $this->dataCollectionFactory->create();
        foreach ($rows as $data) {
            $options = [];
            foreach ((array) ($data['options'] ?? []) as $optionData) {
                $option = $this->optionFactory->create();
                $option->setData($optionData);
                $options[$option->getId()] = $option;
            }
            unset($data['options']);
            $rating = $this->ratingFactory->create();
            $rating->setData($data);
            $rating->setData('options', $options);
            $collection->addItem($rating);
        }
        return $collection;
    }
}
