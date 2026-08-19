<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Pricing\Price\LowestPriceOptionsProvider;
use Magento\Framework\Registry;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/**
 * Feed the configurable price resolver the OpenSearch child SHELLS instead of a native
 * child collection.
 *
 * Core ConfigurablePriceResolver::resolvePrice() iterates
 * LowestPriceOptionsProvider::getProducts(), which builds a native product collection
 * (collectionFactory->create()->addIdFilter(...)). Those native children bypass the shell
 * price path, so each one resolves its catalog-rule + tier price from MySQL — one
 * catalogrule_product_price + one catalog_product_entity_tier_price query PER child (the
 * ~660-pair N+1 on a large configurable PDP).
 *
 * When the parent is an OS-hydrated shell, its children are already built as shells (in the
 * `child_products` registry) carrying indexed catalog_rule_price + tier_prices. Returning
 * those shells makes CatalogRulePrice/TierPrice serve from the doc — zero price SQL. We only
 * substitute when the registry shells match THIS product's own child ids, otherwise we defer
 * to native (correct prices over fast prices).
 */
class LowestPriceOptionsProviderPlugin
{
    public function __construct(
        private Registry $registry,
        private readonly ShellProductBuilder $shellProductBuilder
    ) {
    }

    /**
     * @param LowestPriceOptionsProvider $subject
     * @param callable $proceed
     * @param ProductInterface $product
     * @return ProductInterface[]
     */
    public function aroundGetProducts(
        LowestPriceOptionsProvider $subject,
        callable $proceed,
        ProductInterface $product
    ) {
        if ($product instanceof ShellNoEavProduct) {
            // Prefer this product's own child set; the doc-id match below still guards the
            // legacy global key against a different configurable's children.
            $shells = $this->registry->registry('child_products_' . (int) $product->getId())
                ?: $this->registry->registry('child_products');
            $docChildren = $product->getChildProducts();

            if (is_array($shells) && $shells && is_array($docChildren) && $docChildren) {
                $docIds = [];
                foreach ($docChildren as $child) {
                    $cid = (int)($child['entity_id'] ?? 0);
                    if ($cid) {
                        $docIds[$cid] = true;
                    }
                }

                // Only substitute when every registry shell belongs to this product's doc —
                // guards against a different configurable's children lingering in the registry.
                $matches = $docIds !== [];
                foreach ($shells as $shell) {
                    if (!isset($docIds[(int)$shell->getId()])) {
                        $matches = false;
                        break;
                    }
                }

                if ($matches) {
                    return $this->preferInStock($shells, $docChildren) ?? $proceed($product);
                }
            }

            // v2.5.1: SELF-SUFFICIENT substitution. The registry handshake above depends on the
            // hydration path having registered this exact parent's shells earlier in the request —
            // a timing coupling that a support case (Magento 2.4.9 + MSI) showed can silently miss,
            // at which point proceed() runs the native select whose MSI-injected stock-status join
            // is one query PER CONFIGURABLE on a listing (the reported "12 stock queries on a cold
            // PLP"). The parent shell already CARRIES everything needed in its own doc data, so
            // when the registry cannot answer, build the child shells right here — pure PHP from
            // the indexed arrays, zero SQL — instead of falling through to the database.
            if (is_array($docChildren) && $docChildren) {
                try {
                    $built = [];
                    foreach ($docChildren as $childDoc) {
                        if (!is_array($childDoc) || empty($childDoc['entity_id'])) {
                            $built = [];
                            break;
                        }
                        $shell = $this->shellProductBuilder->buildNoEavProductFromOsDoc($childDoc);
                        if (!$shell || !$shell->getId()) {
                            $built = [];
                            break;
                        }
                        $built[] = $shell;
                    }
                    if ($built) {
                        $substituted = $this->preferInStock($built, $docChildren);
                        if ($substituted !== null) {
                            return $substituted;
                        }
                    }
                } catch (\Throwable $e) {
                    // Correct prices over fast prices: any doubt defers to the native provider.
                }
            }
        }

        return $proceed($product);
    }

    /**
     * Keep only the children the doc says are in stock — the same semantics MSI's
     * StockStatusBaseSelectProcessor enforces with its SQL join, so the "as low as" price never
     * comes from an unbuyable variant. Returns null when NO child is in stock: an all-out-of-stock
     * configurable is the edge where native behaviour (and its own display rules) should decide,
     * not us.
     *
     * @param ShellNoEavProduct[] $shells
     * @param array<int, array<string, mixed>> $docChildren
     * @return ShellNoEavProduct[]|null
     */
    private function preferInStock(array $shells, array $docChildren): ?array
    {
        $inStockIds = [];
        foreach ($docChildren as $child) {
            if (!empty($child['is_in_stock']) && !empty($child['entity_id'])) {
                $inStockIds[(int) $child['entity_id']] = true;
            }
        }
        if ($inStockIds === []) {
            return null;
        }
        $filtered = array_values(array_filter(
            $shells,
            static fn ($shell) => isset($inStockIds[(int) $shell->getId()])
        ));
        return $filtered !== [] ? $filtered : null;
    }
}
