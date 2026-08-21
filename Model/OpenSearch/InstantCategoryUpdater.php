<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\OpenSearch;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\App\Cache\Type\Collection as CollectionCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject\IdentityInterface;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagento\Model\Indexer\CategoryIndexer;

/**
 * Reproject a saved category into OpenSearch immediately, and purge what depended on it.
 *
 * The category counterpart of InstantProductUpdater. It matters more here than it does for
 * products once category ATTRIBUTES are served from the index: before that, a stale category
 * document only affected menus and child lists, whereas now the name, description, meta tags and
 * layout of the page itself come from it. Without this, an admin editing a category would see the
 * old page until the scheduled indexer ran.
 *
 * WHICH CATEGORIES GET REPROJECTED
 * --------------------------------
 * Not just the one that was saved:
 *
 *  - ANCESTORS, always. children_count and all_children are stored ON the parent, so creating,
 *    disabling or moving a category changes documents other than its own. Ancestors come straight
 *    out of the category's own path, so this is a handful of ids, not a tree walk.
 *
 *  - DESCENDANTS, only when the path or url_key changed. A rename or a move rewrites the
 *    url_path of everything beneath it; an ordinary attribute edit does not, and reprojecting a
 *    whole subtree on every description change would make routine admin saves expensive.
 *
 * Best-effort throughout: a save must never fail because OpenSearch was briefly unavailable, so
 * every step is caught and logged, and the scheduled mview indexer remains the backstop.
 */
class InstantCategoryUpdater
{
    public function __construct(
        private readonly CategoryIndexer $categoryIndexer,
        private readonly CategoryDataProvider $categoryData,
        private readonly WriteLog $writeLog,
        private readonly EventManager $eventManager,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    public function updateOnSave(Category $category): void
    {
        $categoryId = (int) $category->getId();
        if ($categoryId <= 0) {
            return;
        }

        try {
            $this->categoryIndexer->executeList($this->affectedIds($category));
            // Make the write searchable now rather than at the next refresh interval, so the very
            // next storefront request sees it instead of the one after that.
            $this->categoryIndexer->refreshIndex();
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] instant category update: reproject failed for category '
                . $categoryId . ': ' . $e->getMessage()
            );
        }

        try {
            $this->purge($category);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] instant category update: cache purge failed for category '
                . $categoryId . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * The saved category, its ancestors, and — only when the tree position or url key moved — its
     * descendants.
     *
     * @return int[]
     */
    private function affectedIds(Category $category): array
    {
        $categoryId = (int) $category->getId();
        $ids = [$categoryId];

        // Ancestors, from the path the category itself carries. Excludes the root/entity itself.
        foreach (explode('/', (string) $category->getPath()) as $part) {
            $ancestorId = (int) $part;
            if ($ancestorId > 0 && $ancestorId !== $categoryId) {
                $ids[] = $ancestorId;
            }
        }

        if ($this->movedOrRenamed($category)) {
            foreach ($this->descendantIds($categoryId) as $descendantId) {
                $ids[] = $descendantId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Did this save change where the category sits, or the url key everything below it inherits?
     */
    private function movedOrRenamed(Category $category): bool
    {
        foreach (['path', 'url_key', 'url_path', 'is_active'] as $code) {
            $orig = $category->getOrigData($code);
            // A category with no orig data is newly created — treat as moved so its ancestors and
            // any pre-existing subtree are refreshed.
            if ($orig === null && $category->getData($code) !== null) {
                return true;
            }
            if ($orig !== null && (string) $orig !== (string) $category->getData($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Descendant ids, read from the in-memory indexed tree rather than the database — this runs
     * inside a save, and walking catalog_category_entity here would put a query back on the very
     * path the module exists to keep off it.
     *
     * @return int[]
     */
    private function descendantIds(int $categoryId): array
    {
        $doc = $this->categoryData->getById($categoryId);
        $path = (string) ($doc['path'] ?? '');
        if ($path === '') {
            return [];
        }

        $prefix = $path . '/';
        $ids = [];
        foreach ($this->categoryData->getAll() as $id => $candidate) {
            if ((int) $id !== $categoryId
                && strpos((string) ($candidate['path'] ?? ''), $prefix) === 0
            ) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Purge the full-page cache for this category and its ancestors.
     *
     * Ancestors are included because a category's name and status show up in their menus and child
     * listings, so refreshing only the edited category would leave the parent's navigation showing
     * the old label.
     */
    private function purge(Category $category): void
    {
        $tags = [];
        foreach ($this->affectedIds($category) as $id) {
            $tags[] = Category::CACHE_TAG . '_' . $id;
        }

        // The COLLECTIONS cache has to be cleaned separately, and by type.
        //
        // clean_cache_by_tags is consumed only by PageCache, CacheInvalidate, Theme and the
        // GraphQL resolver cache -- nothing wires it to the collections cache. Measured: with the
        // index already carrying the new value, a category page kept rendering the previous
        // meta_title until `cache:clean collections` ran, because the category data it rendered
        // from came out of a cached collection rather than out of the index.
        //
        // Cleaning the whole type is coarser than tag-matching, and is the deliberate choice:
        // collection entries are not tagged per category, so there is nothing finer to match on,
        // and the alternative -- leaving them -- is the stale page this class exists to prevent.
        // Category saves are rare and these collections rebuild cheaply.
        try {
            $this->cacheTypeList->cleanType(CollectionCache::TYPE_IDENTIFIER);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] instant category update: could not clean the collections cache: '
                . $e->getMessage()
            );
        }

        $tags = array_values(array_unique(array_filter(array_map('strval', $tags))));
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
}
