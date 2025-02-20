<?php

namespace ParkkTech\FastMagento\Model\ResourceModel\Product;

use Magento\Framework\ObjectManagerInterface;

/**
 * Factory for IndexerProductCollection
 */
class IndexerProductCollectionFactory
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * Constructor
     *
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create a new instance of IndexerProductCollection
     *
     * @param array $data
     * @return IndexerProductCollection
     */
    public function create(array $data = [])
    {
        return $this->objectManager->create(IndexerProductCollection::class, $data);
    }
}
