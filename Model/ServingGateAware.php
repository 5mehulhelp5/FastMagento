<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model;

use Magento\Framework\App\ObjectManager;

/**
 * Gives a hook a cheap check of the serving master switch (see ServingGate).
 *
 * The gate is resolved lazily rather than injected: many gated classes extend core classes, and
 * adding a constructor argument would mean copying core's constructor signature into each of them.
 */
trait ServingGateAware
{
    private ?ServingGate $fmServingGate = null;

    private function isServingEnabled(): bool
    {
        return $this->servingGate()->isEnabled();
    }

    private function servingGate(): ServingGate
    {
        return $this->fmServingGate ??= ObjectManager::getInstance()->get(ServingGate::class);
    }
}
