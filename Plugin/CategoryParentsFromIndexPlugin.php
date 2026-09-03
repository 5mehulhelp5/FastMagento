<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryModelBuilder;

/**
 * The two ancestor walks a category page performs, answered from the indexed tree:
 *
 *  - getParentCategories(): the breadcrumb trail — the active categories on the path below
 *    the store root, with their request paths (one EAV + url_rewrite query natively);
 *  - getParentDesignCategory(): the nearest category on the path (self included) that does
 *    not inherit its design settings (one EAV query with a UNION natively).
 *
 * Same gate as CategoryRepositoryFromIndexPlugin: storefront only, and any category the
 * index cannot answer for hands the call back to the database.
 */
class CategoryParentsFromIndexPlugin
{
    public function __construct(
        private readonly CategoryModelBuilder $builder,
        private readonly CategoryFactory $categoryFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly AppState $appState
    ) {
    }

    public function aroundGetParentCategories(Category $subject, callable $proceed)
    {
        try {
            if (!$this->enabled()) {
                return $proceed();
            }
            $store = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $rootId = (int) $store->getRootCategoryId();
            $result = [];
            foreach (array_map('intval', (array) $subject->getPathIds()) as $id) {
                if ($id === 1 || $id === $rootId) {
                    continue;   // tree root and store root are not breadcrumb items
                }
                $doc = $this->builder->doc($id);
                if ($doc === null) {
                    return $proceed();
                }
                if ((int) ($doc['is_active'] ?? 0) !== 1) {
                    continue;
                }
                $category = $this->builder->build($id, $storeId);
                if ($category === null) {
                    return $proceed();
                }
                $result[$id] = $category;
            }
            return $result;
        } catch (\Throwable $e) {
            return $proceed();
        }
    }

    public function aroundGetParentDesignCategory(Category $subject, callable $proceed)
    {
        try {
            if (!$this->enabled()) {
                return $proceed();
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            foreach (array_reverse(array_map('intval', (array) $subject->getPathIds())) as $id) {
                $doc = $this->builder->doc($id);
                if ($doc === null) {
                    return $proceed();
                }
                if ((int) ($doc['level'] ?? 0) === 0) {
                    continue;
                }
                if (!empty($doc['fm_attrs']['custom_use_parent_settings'])) {
                    continue;
                }
                $category = $this->builder->build($id, $storeId);
                return $category ?? $proceed();
            }
            // Nothing on the path owns its design: the native collection's "first item" is an
            // empty category, and Design::getDesignSettings() treats it as "no custom design".
            return $this->categoryFactory->create();
        } catch (\Throwable $e) {
            return $proceed();
        }
    }

    private function enabled(): bool
    {
        return $this->appState->getAreaCode() === Area::AREA_FRONTEND
            && $this->scopeConfig->isSetFlag(CategoryRepositoryFromIndexPlugin::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            && $this->builder->isAvailable();
    }
}
