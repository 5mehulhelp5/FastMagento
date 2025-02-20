<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProductFactory;

class FrontendProductPlugin
{
    private $appState;
    private $shellNoEavProductFactory;

    public function __construct(
        State $appState,
        ShellNoEavProductFactory $shellNoEavProductFactory
    ) {
        $this->appState = $appState;
        $this->shellNoEavProductFactory = $shellNoEavProductFactory;
    }

    /**
     * Around Plugin: Swap product model with ShellNoEavProduct in frontend.
     *
     * @param Product $subject
     * @param callable $proceed
     * @return Product
     */
    public function aroundLoad(Product $subject, callable $proceed)
    {
        // Only modify product model in the frontend scope
        if ($this->appState->getAreaCode() === 'frontend') {
            return $this->shellNoEavProductFactory->create();
        }

        return $proceed();
    }
}
