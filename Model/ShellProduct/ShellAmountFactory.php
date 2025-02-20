<?php
namespace ParkkTech\FastMagento\Model\ShellProduct;

use Magento\Framework\ObjectManagerInterface;

class ShellAmountFactory
{
    private ObjectManagerInterface $objectManager;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * We accept an array with 'value' => float, 'adjustments' => array, etc.
     */
    public function create(array $data = []): ShellAmount
    {
        return $this->objectManager->create(ShellAmount::class, $data);
    }
}
