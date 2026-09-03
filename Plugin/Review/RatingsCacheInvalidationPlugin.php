<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Review;

use Magento\Framework\App\CacheInterface;

/**
 * Drops the cached review-form rating set whenever a rating or a rating option is saved or
 * deleted (admin: Stores > Rating). See RatingsCachePlugin.
 */
class RatingsCacheInvalidationPlugin
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function afterSave($subject, $result)
    {
        $this->cache->clean([RatingsCachePlugin::CACHE_TAG]);
        return $result;
    }

    public function afterDelete($subject, $result)
    {
        $this->cache->clean([RatingsCachePlugin::CACHE_TAG]);
        return $result;
    }
}
