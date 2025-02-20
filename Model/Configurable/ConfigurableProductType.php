<?php

namespace ParkkTech\FastMagento\Model\Configurable;

use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as CoreConfigurable;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Relation as ProductRelation;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Attribute\OptionProvider;
use Magento\ConfigurableProduct\Model\AttributeOptionProviderInterface;

class ConfigurableProductType extends CoreConfigurable
{
    private ProductRepositoryInterface $productRepository;

    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        ProductRelation $catalogProductRelation, // ✅ Correct dependency
        ScopeResolverInterface $scopeResolver,
        AttributeOptionProviderInterface $attributeOptionProvider,
        OptionProvider $optionProvider,
        ProductRepositoryInterface $productRepository,
                                                          $connectionName = null
    ) {
        parent::__construct(
            $context,
            $catalogProductRelation,
            $connectionName,
            $scopeResolver,
            $attributeOptionProvider,
            $optionProvider
        );
        $this->productRepository = $productRepository;
    }

    /**
     * ✅ Fetch Parent IDs from OpenSearch Instead of SQL
     */
    public function getParentIdsByChild($childId)
    {
        try {
            $product = $this->productRepository->getById($childId);
            if ($product->getData('parent_ids')) {
                return $product->getData('parent_ids');
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return parent::getParentIdsByChild($childId);
        }

        return parent::getParentIdsByChild($childId);
    }
}
