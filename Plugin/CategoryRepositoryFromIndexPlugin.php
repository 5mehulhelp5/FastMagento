<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\CategoryRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\App\Area;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryModelBuilder;

/**
 * CategoryRepository::get() from the category index on the storefront.
 *
 * The category controller loads the current category through the repository on every
 * uncached listing: an entity read, an EntityManager read, an exists-check, then the design
 * walk and the breadcrumb walk on top (see CategoryParentsFromIndexPlugin). The index already
 * holds every field those readers use. Storefront only: admin, API and GraphQL keep the
 * database, and a category the index does not know falls through to it as well.
 */
class CategoryRepositoryFromIndexPlugin
{
    public const XML_PATH_ENABLED = 'fastmagento/serving/serve_category_load';

    public function __construct(
        private readonly CategoryModelBuilder $builder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly AppState $appState
    ) {
    }

    public function aroundGet(CategoryRepository $subject, callable $proceed, $categoryId, $storeId = null)
    {
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND
                || !$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
                || !$this->builder->isAvailable()
            ) {
                return $proceed($categoryId, $storeId);
            }
            $storeId = $storeId === null ? (int) $this->storeManager->getStore()->getId() : (int) $storeId;
            $category = $this->builder->build((int) $categoryId, $storeId);
            return $category ?? $proceed($categoryId, $storeId);
        } catch (\Throwable $e) {
            return $proceed($categoryId, $storeId);
        }
    }
}
