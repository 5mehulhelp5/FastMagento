<?php
namespace ParkkTech\FastMagento\Model\Indexer;

use Magento\Framework\DataObject;

/**
 * Minimal provider to satisfy <fieldset> provider reference in indexer.xml.
 */
class DummyFieldsProvider implements DummyFieldsProviderInterface
{
    public function getFields(DataObject $entity): array
    {
        // We don't need to transform or store anything here
        return [];
    }
}
