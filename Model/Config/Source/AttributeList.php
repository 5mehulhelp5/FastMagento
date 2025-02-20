<?php

namespace ParkkTech\FastMagento\Model\Config\Source;

use Magento\Eav\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory;

class AttributeList implements OptionSourceInterface
{
    private $attributeCollectionFactory;
    private $eavConfig;

    public function __construct(
        CollectionFactory $attributeCollectionFactory,
        Config $eavConfig
    ) {
        $this->attributeCollectionFactory = $attributeCollectionFactory;
        $this->eavConfig = $eavConfig;
    }

    public function toOptionArray()
    {
        $entityTypeId = $this->eavConfig->getEntityType('catalog_product')->getEntityTypeId();
        $attributes = $this->attributeCollectionFactory->create()
            ->setEntityTypeFilter($entityTypeId)
            ->addVisibleFilter();

        $options = [];
        foreach ($attributes as $attribute) {
            $options[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => $attribute->getFrontendLabel() ?: $attribute->getAttributeCode()
            ];
        }

        return $options;
    }
}
