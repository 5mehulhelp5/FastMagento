<?php
namespace ParkkTech\FastMagento\Model\Indexer;

use Magento\Framework\DataObject;

interface DummyFieldsProviderInterface
{
    public function getFields(DataObject $entity): array;
}
