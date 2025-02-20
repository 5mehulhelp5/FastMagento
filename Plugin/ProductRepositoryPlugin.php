<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Api\SearchResultsInterface;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;

/**
 * A plugin that intercepts ProductRepository calls and converts
 * the returned ProductInterface(s) into your ShellProduct model.
 */
class ProductRepositoryPlugin
{
    /** @var ShellProductBuilder */
    private $shellProductBuilder;

    public function __construct(
        ShellProductBuilder $shellProductBuilder
    ) {
        $this->shellProductBuilder = $shellProductBuilder;
    }

    /**
     * Intercept getById($productId, $editMode = false, $storeId = null, $forceReload = false)
     * to return a ShellProduct instead of a plain product.
     */
    public function aroundGetById(
        ProductRepositoryInterface $subject,
        callable $proceed,
                                   $productId,
                                   $editMode = false,
                                   $storeId = null,
                                   $forceReload = false
    ) {
        // 1) Call the original method
        /** @var ProductInterface $original */
        $original = $proceed($productId, $editMode, $storeId, $forceReload);

        // 2) Convert the plain product into your shell product
        $shell = $this->shellProductBuilder->convertToShellProduct($original);

        // 3) Return the shell
        return $shell;
    }

    /**
     * Intercept get($sku, $editMode = false, $storeId = null, $forceReload = false)
     */
    public function aroundGet(
        ProductRepositoryInterface $subject,
        callable $proceed,
                                   $sku,
                                   $editMode = false,
                                   $storeId = null,
                                   $forceReload = false
    ) {
        // 1) Original
        $original = $proceed($sku, $editMode, $storeId, $forceReload);

        // 2) Convert
        $shell = $this->shellProductBuilder->convertToShellProduct($original);

        return $shell;
    }

    /**
     * Intercept getList(SearchCriteriaInterface $searchCriteria) => SearchResultsInterface
     * We must convert each product in the returned result to a ShellProduct.
     */
    public function aroundGetList(
        ProductRepositoryInterface $subject,
        callable $proceed,
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    ) {
        /** @var SearchResultsInterface $searchResults */
        $searchResults = $proceed($searchCriteria);

        // Loop through all items, convert each to ShellProduct
        $items = $searchResults->getItems(); // array of ProductInterface
        foreach ($items as $key => $productInterface) {
            $shell = $this->shellProductBuilder->convertToShellProduct($productInterface);
            $items[$key] = $shell;
        }

        // Replace the items in the search result
        $searchResults->setItems($items);

        // Return the updated search results
        return $searchResults;
    }
}
