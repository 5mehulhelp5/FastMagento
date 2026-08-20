<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Pricing\Price\SpecialPriceBulkResolverInterface;
use Magento\Framework\Data\Collection\AbstractDb as AbstractCollection;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/**
 * Build the listing's "does this product have a special price?" map from the indexed documents.
 *
 * Core resolves this in one bulk query per listing (SpecialPriceBulkResolver), joining
 * catalog_product_entity to catalog_product_super_link, catalog_product_website and
 * catalog_product_index_price to compute `final_price < price` for every row on the page. As a
 * bulk query it is already well behaved — it exists to replace an N+1 — but on a listing
 * FastMagento is serving it is the last catalogue read standing, and it is asking for two numbers
 * that are already on each document we fetched.
 *
 * Only substitutes when EVERY item on the page is an OS-hydrated shell carrying both numbers.
 * A single native item, or a single shell missing final_price, defers to core: a price badge
 * silently missing from a product on sale is a worse outcome than one query.
 */
class SpecialPriceBulkResolverPlugin
{
    /**
     * @param SpecialPriceBulkResolverInterface $subject
     * @param callable $proceed
     * @param int $storeId
     * @param AbstractCollection|null $productCollection
     * @return array
     */
    public function aroundGenerateSpecialPriceMap(
        SpecialPriceBulkResolverInterface $subject,
        callable $proceed,
        int $storeId,
        $productCollection
    ) {
        if ($productCollection === null) {
            return $proceed($storeId, $productCollection);
        }

        try {
            $items = $productCollection->getItems();
            if (!$items) {
                return $proceed($storeId, $productCollection);
            }

            $map = [];
            foreach ($items as $item) {
                if (!$item instanceof ShellNoEavProduct) {
                    return $proceed($storeId, $productCollection);
                }

                $price = $item->getData('price');
                $final = $item->getData('final_price');
                if ($price === null || $final === null) {
                    return $proceed($storeId, $productCollection);
                }

                // Same comparison core makes in SQL: final_price < price.
                $map[(int) $item->getId()] = (float) $final < (float) $price ? 1 : 0;
            }

            return $map;
        } catch (\Throwable $e) {
            return $proceed($storeId, $productCollection);
        }
    }
}
