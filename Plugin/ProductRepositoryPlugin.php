<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Helper\WriteLog;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;

/**
 * A plugin that intercepts ProductRepository calls and converts
 * the returned ProductInterface(s) into your ShellProduct model.
 */
class ProductRepositoryPlugin
{
    /** OS-serve on the customer-facing storefront (frontend) and headless GraphQL areas. */
    private const SERVABLE_AREAS = [Area::AREA_FRONTEND, Area::AREA_GRAPHQL];

    /**
     * @param State $state
     * @param WriteLog $writeLog
     * @param ShellProductBuilder $shellProductBuilder
     * @param OpenSearchPdpFetcher $openSearchPdpFetcher
     * @param ProductIndexer $productIndexer
     */
    public function __construct(
        private readonly State $state,
        private readonly WriteLog $writeLog,
        private readonly ShellProductBuilder $shellProductBuilder,
        private readonly OpenSearchPdpFetcher $openSearchPdpFetcher,
        private readonly ProductIndexer $productIndexer,
        private readonly ProductResource $productResource
    ) {
    }

    /**
     * Intercept getById($productId, $editMode = false, $storeId = null, $forceReload = false)
     * to return a ShellProduct instead of a plain product.
     */
    public function aroundGetById(
        ProductRepositoryInterface $subject,
        callable $proceed,
        $productId,
        $editMode = false,
        $storeId = null,
        $forceReload = false
    ) {
        if (!in_array($this->state->getAreaCode(), self::SERVABLE_AREAS, true)) {
            return $proceed($productId, $editMode, $storeId, $forceReload);
        }

        // Empty/zero id = no product to fetch; avoid a guaranteed OpenSearch miss.
        if (empty($productId)) {
            return $proceed($productId, $editMode, $storeId, $forceReload);
        }

        $doc = $this->openSearchPdpFetcher->fetchPdpById($productId);
        if (!$doc) {
            // Warm-on-miss (read-through, like a cache): product isn't in OpenSearch yet
            // (mid-reindex / stale / never indexed). Load it natively once, PUSH it into
            // OpenSearch, then serve FROM OpenSearch so the serving layer stays OS-only.
            $native = $proceed($productId, $editMode, $storeId, $forceReload);
            if ($native && $native->getId()) {
                $this->productIndexer->indexProductObject($native);
                $doc = $this->openSearchPdpFetcher->fetchPdpById($productId);
            }
            if (!$doc) {
                // OpenSearch still unavailable (e.g. OS down) — degrade to native.
                $this->writeLog->writeErrorLog(
                    'Product ID ' . $productId . ' could not be warmed into OpenSearch; serving native.'
                );
                return $native ?? $proceed($productId, $editMode, $storeId, $forceReload);
            }
        }

        return $this->shellProductBuilder->buildNoEavProductFromOsDoc($doc);
    }

    /**
     * Intercept get($sku, $editMode = false, $storeId = null, $forceReload = false)
     */
    public function aroundGet(
        ProductRepositoryInterface $subject,
        callable $proceed,
                                   $sku,
                                   $editMode = false,
                                   $storeId = null,
                                   $forceReload = false
    ) {
        if (!in_array($this->state->getAreaCode(), self::SERVABLE_AREAS, true)) {
            return $proceed($sku, $editMode, $storeId, $forceReload);
        }

        // The native get() loads a fresh Product via $product->load($id) and ignores
        // the return value. FrontendProductPlugin::aroundLoad returns a shell built
        // from the OpenSearch doc WITHOUT hydrating the subject, so the repository
        // then caches an empty product and dies in prepareSku(null). Resolve the id
        // and route through getById(), which our aroundGetById serves correctly.
        $productId = $this->productResource->getIdBySku($sku);
        if (!$productId) {
            throw new NoSuchEntityException(__('The product with SKU "%1" does not exist.', $sku));
        }
        return $subject->getById($productId, $editMode, $storeId, $forceReload);
    }

    /**
     * Intercept getList(SearchCriteriaInterface $searchCriteria) => SearchResultsInterface
     * For frontend, we can optimize by using OpenSearch data if needed.
     * For now, fall back to the native implementation.
     */
    public function aroundGetList(
        ProductRepositoryInterface $subject,
        callable $proceed,
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    ) {
        if (!in_array($this->state->getAreaCode(), self::SERVABLE_AREAS, true)) {
            return $proceed($searchCriteria);
        }

        // For frontend, use native implementation for now
        // TODO: Optimize with OpenSearch data if needed
        return $proceed($searchCriteria);
    }
}
