<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\OpenSearch;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;

/**
 * Instant product update — makes a catalogue edit visible on the storefront the moment it is
 * saved, instead of waiting on the scheduled indexer + full-page-cache lag that gives Magento its
 * reputation for "I saved it but I don't see it".
 *
 * Driven off catalog_product_save_commit_after (see Observer\InstantUpdateOnSave) — i.e. only once
 * the save has COMMITTED, so a rolled-back save never leaves OpenSearch or the cache ahead of the
 * database. On each qualifying save this does two things, both best-effort:
 *   1. Reprojects the saved product AND any composite parents (configurable / grouped / bundle)
 *      straight into the OpenSearch serving index via the FastMagento product indexer, so the
 *      change is queryable immediately — the mview changelog is bypassed, not waited on.
 *   2. Purges the product's full-page cache (and, when enabled, its category pages) by dispatching
 *      Magento's own clean_cache_by_tags event, so the rendered HTML is regenerated on next view.
 *
 * Every step is wrapped so a reprojection or purge failure is logged and never breaks the save.
 */
class InstantProductUpdater
{
    public function __construct(
        private readonly ProductIndexer $productIndexer,
        private readonly ResourceConnection $resource,
        private readonly OpenSearchConfig $config,
        private readonly WriteLog $writeLog,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * Reproject + cache-purge the given just-committed product. Best-effort; each half is isolated
     * so one failing never prevents the other or surfaces to the admin save.
     */
    public function updateOnSave(Product $product): void
    {
        $productId = (int) $product->getId();
        if ($productId <= 0) {
            return;
        }

        try {
            $this->reproject($productId);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] instant update: reproject failed for product ' . $productId . ': ' . $e->getMessage()
            );
        }

        try {
            $this->purge($product);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] instant update: cache purge failed for product ' . $productId . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Full reprojection of the saved product plus any composite parent, straight to OpenSearch —
     * bypasses the scheduled mview so the doc is current immediately.
     */
    private function reproject(int $productId): void
    {
        $ids = array_values(array_unique(array_merge([$productId], $this->getParentIds([$productId]))));
        $this->productIndexer->executeList($ids);
    }

    /**
     * Purge the full-page cache for the product's tags by dispatching the standard
     * clean_cache_by_tags event. Core's built-in-FPC and Varnish observers each pick it up, gated
     * on their own cache type, and the Varnish one anchors every tag (so cat_p_12 can't ban
     * cat_p_120); a CDN module such as Fastly hooks the same event. Nothing happens when the page
     * cache is off. We pass a lightweight IdentityInterface carrier so the exact tag set we want —
     * product identities plus its category pages — flows through core's tag resolver unchanged.
     */
    private function purge(Product $product): void
    {
        $tags = $this->cacheTags($product);
        if (!$tags) {
            return;
        }

        $carrier = new class ($tags) implements IdentityInterface {
            /** @param string[] $tags */
            public function __construct(private array $tags)
            {
            }

            /** @return string[] */
            public function getIdentities(): array
            {
                return $this->tags;
            }
        };

        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $carrier]);
    }

    /**
     * Cache tags to purge: the product's own identities (cat_p_<id>, plus category tags Magento
     * itself adds on a status/visibility change) and — when enabled — a cat_c_<id> tag for each
     * real category the product lives in, so its listing pages refresh too.
     *
     * @return string[]
     */
    private function cacheTags(Product $product): array
    {
        $tags = $product->getIdentities();

        if ($this->config->isInstantPurgeCategoriesEnabled()) {
            foreach ($this->getCategoryIds((int) $product->getId()) as $categoryId) {
                $tags[] = Category::CACHE_TAG . '_' . $categoryId;
            }
        }

        return array_values(array_unique(array_filter(array_map('strval', $tags))));
    }

    /**
     * Composite parents of the given children (configurable/grouped via super_link, all composites
     * via catalog_product_relation) — direct DB, mirroring StockSyncer's deliberately-native lookup.
     *
     * @param int[] $childIds
     * @return int[]
     */
    private function getParentIds(array $childIds): array
    {
        if (!$childIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $parents = [];

        $parents[] = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_super_link'), ['parent_id'])
                ->where('product_id IN (?)', $childIds)
        );
        $parents[] = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_relation'), ['parent_id'])
                ->where('child_id IN (?)', $childIds)
        );

        return array_values(array_unique(array_map('intval', array_merge(...$parents))));
    }

    /**
     * Real (non-root) category ids the product is assigned to. Direct DB — the save has committed,
     * so a freshly-changed assignment is visible. Root (1) / default (2) are excluded: their tags
     * sit on huge swaths of pages and purging them would needlessly wipe most of the cache.
     *
     * @return int[]
     */
    private function getCategoryIds(int $productId): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_category_product'), ['category_id'])
                ->where('product_id = ?', $productId)
                ->where('category_id > ?', 2)
        ));
    }
}
