<?php

namespace ParkkTech\FastMagento\Model\ShellProduct;

use Magento\Framework\Pricing\Amount\AmountInterface;

/**
 * "ShellAmount" matching an older Magento AmountInterface signature:
 *   public function getValue($exclude = null);
 * Instead of the newer typed:
 *   public function getValue(): float;
 */
class ShellAmount implements AmountInterface
{
    /**
     * The base numeric price.
     */
    private float $value;

    /**
     * Optional array of adjustments, keyed by code => float.
     */
    private array $adjustments;

    /**
     * @param float $value
     * @param array $adjustments
     */
    public function __construct(float $value = 0.0, array $adjustments = [])
    {
        $this->value = $value;
        $this->adjustments = $adjustments;
    }

    /**
     * Must match EXACTLY:
     *   public function getValue($exclude = null);
     */
    public function getValue($exclude = null)
    {
        // Return the numeric price as float
        return $this->value;
    }

    /**
     * If your interface still uses getBaseAmount() without a param
     */
    public function getBaseAmount()
    {
        return $this->value;
    }

    public function getTotalAdjustmentAmount()
    {
        return array_sum($this->adjustments);
    }

    public function getAdjustmentAmounts()
    {
        return $this->adjustments;
    }

    public function getCompoundValue()
    {
        return $this->value + $this->getTotalAdjustmentAmount();
    }

    public function __toString()
    {
        return (string)$this->value;
    }

    /**
     * Older versions often have:
     *   public function hasAdjustment($code);
     *   public function getAdjustmentAmount($code);
     */
    public function hasAdjustment($code)
    {
        return isset($this->adjustments[$code]);
    }

    public function getAdjustmentAmount($code)
    {
        return $this->adjustments[$code] ?? 0.0;
    }
}
