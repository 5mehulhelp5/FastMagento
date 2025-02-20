<?php

namespace ParkkTech\FastMagento\Model\ShellProduct;

use Magento\Framework\ObjectManagerInterface;

class ShellDataProductFactory
{
    private ObjectManagerInterface $objectManager;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create a new ShellDataProduct with optional 'doc' and 'priceInfo'.
     * The DI container will inject ProductUrl automatically.
     *
     * @param array $data e.g. ['doc' => $doc, 'priceInfo' => $shellPriceInfo]
     */
    public function create(array $data = []): ShellDataProduct
    {
        // This calls the DI container to create ShellDataProduct with
        // all dependencies from di.xml plus any $data overrides.
        return $this->objectManager->create(ShellDataProduct::class, $data);
    }
}
