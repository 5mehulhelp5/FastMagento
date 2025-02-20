<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\ShellProduct\ShellProduct;

/**
 * Plugin to apply ShellProduct in frontend, keeping default behavior in backend.
 */
class ShellProductPlugin
{
    /**
     * @var State
     */
    private $appState;

    /**
     * Constructor.
     *
     * @param State $appState
     */
    public function __construct(State $appState)
    {
        $this->appState = $appState;
    }

    /**
     * Around Plugin to modify Product instance in the frontend only.
     *
     * @param Product $subject
     * @param callable $proceed
     * @return Product
     */
    public function aroundLoad(Product $subject, callable $proceed)
    {
        if ($this->appState->getAreaCode() === 'frontend') {
            return new ShellProduct(
                $subject->getContext(),
                $subject->getRegistry(),
                $subject->getExtensionAttributesFactory(),
                $subject->getCustomAttributeFactory(),
                $subject->getStoreManager(),
                $subject->getMetadataService(),
                $subject->getUrlModel(),
                $subject->getProductLink(),
                $subject->getOptionInstance(),
                $subject->getStockItemFactory(),
                $subject->getCatalogProductOptionFactory(),
                $subject->getCatalogProductVisibility(),
                $subject->getCatalogProductStatus(),
                $subject->getMediaConfig(),
                $subject->getTypeInstance(),
                $subject->getModuleManager(),
                $subject->getCatalogProductHelper(),
                $subject->getResource(),
                $subject->getResourceCollection(),
                $subject->getCollectionFactory(),
                $subject->getFilesystem(),
                $subject->getIndexerRegistry(),
                $subject->getFlatIndexerProcessor(),
                $subject->getPriceIndexerProcessor(),
                $subject->getEavIndexerProcessor(),
                $subject->getCategoryRepository(),
                $subject->getImageCacheFactory(),
                $subject->getEntityCollectionProvider(),
                $subject->getLinkTypeProvider(),
                $subject->getProductLinkFactory(),
                $subject->getProductLinkExtensionFactory(),
                $subject->getMediaGalleryEntryConverterPool(),
                $subject->getDataObjectHelper(),
                $subject->getJoinProcessor(),
                $subject->getData()
            );
        }
        return $proceed();
    }
}
