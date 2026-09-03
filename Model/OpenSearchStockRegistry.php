<?php

namespace ParkkTech\FastMagento\Model;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterfaceFactory;
use Magento\CatalogInventory\Api\Data\StockInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Model\Spi\StockRegistryProviderInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\GroupedProduct\Model\Product\Type\Grouped;



class OpenSearchStockRegistry implements StockRegistryInterface
{
    /** @var StockStatusInterfaceFactory */
    private $stockStatusFactory;


    private StoreManagerInterface $storeManager;
    private StockConfigurationInterface $stockConfiguration;
    private StockRegistryProviderInterface $stockRegistryProvider;
    private StockItemRepositoryInterface $stockItemRepository;
    private StockItemCriteriaInterfaceFactory $criteriaFactory;
    private ProductFactory $productFactory;

    private Registry $registry;
    private string $indexName;

    private ProductType $productType;

    /** @var array<string,int|null> per-request sku => entity_id cache (see resolveProductId) */
    private array $skuIdCache = [];

    public function __construct(
        StoreManagerInterface $storeManager,
        StockConfigurationInterface $stockConfiguration,
        StockRegistryProviderInterface $stockRegistryProvider,
        StockItemRepositoryInterface $stockItemRepository,
        StockItemCriteriaInterfaceFactory $criteriaFactory,
        ProductFactory $productFactory,
        ScopeConfigInterface $scopeConfig,
        Registry $registry,
        ProductType $productType,
        StockStatusInterfaceFactory $stockStatusFactory
    ) {
        $this->storeManager = $storeManager;
        $this->stockConfiguration = $stockConfiguration;
        $this->stockRegistryProvider = $stockRegistryProvider;
        $this->stockItemRepository = $stockItemRepository;
        $this->criteriaFactory = $criteriaFactory;
        $this->productFactory = $productFactory;
        $this->registry = $registry;
        $this->productType = $productType;
        $this->stockStatusFactory = $stockStatusFactory;
    }


    public function getStockItem($productId, $scopeId = null): StockItemInterface
    {
        // Check if product exists in registry
        $product = $this->registry->registry('current_product');

        if ($product && (int)$product->getId() === (int)$productId) {
            return $this->buildStockItemFromRegistry($product);
        }

        // If not found in registry, fallback to Magento Core stock management
        return $this->stockRegistryProvider->getStockItem(
            $productId,
            $scopeId ?? $this->stockConfiguration->getDefaultScopeId()
        );
    }




    public function getStockStatus($productId, $scopeId = null): StockStatusInterface
    {
        $product = $this->registry->registry('current_product');

        if ($product && (int)$product->getId() === (int)$productId) {
            return $this->buildStockStatusFromRegistry($product);
        }

        return $this->stockRegistryProvider->getStockStatus(
            $productId,
            $scopeId ?? $this->stockConfiguration->getDefaultScopeId()
        );
    }

    public function getStockItemBySku($productSku, $scopeId = null): StockItemInterface
    {
        $productId = $this->resolveProductId($productSku);
        return $this->getStockItem($productId, $scopeId);
    }



    public function getStockStatusBySku($productSku, $scopeId = null): ?StockStatusInterface
    {
        $productId = $this->resolveProductId($productSku);
        return $this->getStockStatus($productId, $scopeId);
    }


    public function getProductStockStatus($productId, $scopeId = null): ?bool
    {
        $stockStatus = $this->getStockStatus($productId, $scopeId);
        return $stockStatus ? $stockStatus->getStockStatus() : null;
    }


    public function getProductStockStatusBySku($productSku, $scopeId = null): ?bool
    {
        $productId = $this->resolveProductId($productSku);
        return $this->getProductStockStatus($productId, $scopeId);
    }

    public function getLowStockItems($scopeId, $qty, $currentPage = 1, $pageSize = 0)
    {
        $criteria = $this->criteriaFactory->create();
        $criteria->setLimit($currentPage, $pageSize);
        $criteria->setScopeFilter($scopeId);
        $criteria->setQtyFilter('<=', $qty);
        $criteria->addField('qty');
        return $this->stockItemRepository->getList($criteria);
    }


    public function updateStockItemBySku($productSku, StockItemInterface $stockItem)
    {
        $productId = $this->resolveProductId($productSku);
        $websiteId = $stockItem->getWebsiteId() ?: null;
        $origStockItem = $this->getStockItem($productId, $websiteId);
        $data = $stockItem->getData();
        if ($origStockItem->getItemId()) {
            unset($data['item_id']);
        }
        $origStockItem->addData($data);
        $origStockItem->setProductId($productId);
        return $this->stockItemRepository->save($origStockItem)->getItemId();
    }


    public function getStock($scopeId = null): StockInterface
    {
        return $this->stockRegistryProvider->getStock(
            $scopeId ?? $this->stockConfiguration->getDefaultScopeId()
        );
    }


    /**
     * Resolve a sku to its entity_id. This StockRegistry is a shared (singleton) instance, and
     * MSI's PreloadCache observer calls the *BySku methods once per cart line on every quote
     * load — each previously firing a fresh `catalog_product_entity WHERE sku=?` query. sku->id
     * is immutable within a request, so cache it: collapses that per-line N+1 to one lookup per
     * distinct sku (repeats across collectTotals recollects/observers become free).
     */
    private function resolveProductId($productSku): ?int
    {
        $key = (string) $productSku;
        if (array_key_exists($key, $this->skuIdCache)) {
            return $this->skuIdCache[$key];
        }
        $id = $this->productFactory->create()->getIdBySku($productSku);
        return $this->skuIdCache[$key] = ($id !== false && $id !== null ? (int) $id : null);
    }



    private function createStockItem($productId, ?array $stockData): StockItemInterface
    {
        $stockItem = $this->stockItemRepository->get($productId);
        $stockItem->setProductId($productId);
        $stockItem->setIsInStock($stockData['is_in_stock'] ?? false);
        $stockItem->setQty($stockData['qty'] ?? 0);
        return $stockItem;
    }


    /**
     * Retrieve stock for different product types (Simple, Configurable, Grouped, Bundle).
     */
    private function buildStockItemFromRegistry($product): StockItemInterface
    {
        // Resolve the stock item by PRODUCT id via the registry provider — NOT
        // stockItemRepository->get(), which expects a stock_item_id (passing the product
        // id threw "stock item <productId> wasn't found" from the Qtyincrements block).
        // Prefer the stock item already hydrated from the OpenSearch doc (no DB) when present.
        $stockItem = $product->getExtensionAttributes()
            ? $product->getExtensionAttributes()->getStockItem()
            : null;
        if (!$stockItem instanceof StockItemInterface || !$stockItem->getItemId()) {
            $stockItem = $this->stockRegistryProvider->getStockItem(
                $product->getId(),
                $this->stockConfiguration->getDefaultScopeId()
            );
        }

        // Every branch below only OVERRIDES the stock item the provider returned when the served
        // document actually carries the data. Grouped and bundle products used to read
        // getAssociatedProducts() / getBundleChildren(), which a shell built from the index never
        // has, so iterating null was a warning in production and a 500 in developer mode -- and
        // reported every grouped and bundle product as out of stock, so none could be added to
        // the cart. The document indexes children of all three composite types under
        // `child_products` (with is_in_stock and stock_qty per child); when it cannot answer, the
        // provider's database-backed item stands.
        $typeId = $product->getTypeId();
        if ($typeId === $this->productType::TYPE_SIMPLE) {
            if ($product->getData('is_in_stock') !== null) {
                $stockItem->setIsInStock((bool) $product->getData('is_in_stock'));
                $stockItem->setQty($product->getData('stock_qty') ?? $stockItem->getQty());
            }
            return $stockItem;
        }

        if (in_array($typeId, [Configurable::TYPE_CODE, Grouped::TYPE_CODE, ProductType::TYPE_BUNDLE], true)) {
            $children = $product->getData('child_products');
            if (!is_array($children) || !$children) {
                return $stockItem;
            }
            $isInStock = false;
            $stockQty = 0;
            foreach ($children as $child) {
                if (!empty($child['is_in_stock'])) {
                    $isInStock = true;
                }
                $stockQty += (int) ($child['stock_qty'] ?? 0);
            }
            $stockItem->setIsInStock($isInStock);
            $stockItem->setQty($stockQty);
        }

        return $stockItem;
    }
    /**
     * Build Stock Status from Registry Data.
     */
    /**
     * Quantity as the indexed document records it.
     *
     * The projection does not write a top-level `qty`; it writes stock_data.qty and
     * quantity_and_stock_status.qty (the shapes core itself uses). Reading only `qty` therefore
     * silently yielded 0, which is not a harmless default here -- the storefront's
     * "Only N left" message is threshold-driven, so a real stock of 100 reported as 0 changes
     * what the page says.
     */
    private function resolveIndexedQty($product): float
    {
        $direct = $product->getData('qty');
        if ($direct !== null && $direct !== '') {
            return (float) $direct;
        }

        foreach (['stock_data', 'quantity_and_stock_status'] as $key) {
            $data = $product->getData($key);
            if (is_array($data) && isset($data['qty']) && $data['qty'] !== '') {
                return (float) $data['qty'];
            }
        }

        return 0.0;
    }

    private function buildStockStatusFromRegistry($product): StockStatusInterface
    {
        $isInStock = $product->getData('is_in_stock');

        // Build the object outright when the document can answer.
        //
        // This used to call the stock registry provider and then immediately overwrite the status
        // it came back with -- so the cataloginventory_stock_status query it issued was paid for
        // and then discarded. On a product page served from the index that was the last catalogue
        // query left standing, and it was fetching a number we were about to throw away.
        //
        // Display only: this drives the in-stock badge and the "only N left" message. It does not
        // authorise a sale -- order placement re-checks stock by SKU against the database -- so a
        // briefly stale index can show a stale badge but cannot let an oversell through.
        if ($isInStock !== null) {
            /** @var StockStatusInterface $stockStatus */
            $stockStatus = $this->stockStatusFactory->create();
            $stockStatus->setProductId((int) $product->getId());
            $stockStatus->setStockStatus((int) ((bool) $isInStock));
            $stockStatus->setQty($this->resolveIndexedQty($product));

            return $stockStatus;
        }

        // Document predates the field (or carries no stock at all): let the registry answer, which
        // is the pre-existing behaviour including its query.
        /** @var StockStatusInterface $stockStatus */
        $stockStatus = $this->stockRegistryProvider->getStockStatus($product->getId(), null);
        $stockStatus->setStockStatus(false);

        return $stockStatus;
    }

}
