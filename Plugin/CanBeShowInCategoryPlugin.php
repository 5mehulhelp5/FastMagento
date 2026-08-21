<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/**
 * Answer "is this product in that category?" from the served document.
 *
 * Product::canBeShowInCategory() queries catalog_category_product_index_store<N> for a single
 * product/category pair. A product page asks twice -- once from Product::initProduct and once
 * from Design::getDesignSettings while resolving the category-specific layout -- so a served
 * product page paid two index-table round-trips for a membership test.
 *
 * ProductIndexer projects category_ids onto every document, which is the same membership the
 * index table records, so this is an in-array check on data already in memory.
 *
 * Only answers for OS-hydrated shells whose document carries category_ids; anything else, and any
 * document written before that field existed, falls through to the query.
 */
class CanBeShowInCategoryPlugin
{
    private const XML_PATH_SERVE_TREE = 'fastmagento/serving/serve_category_tree';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * @param Product $subject
     * @param callable $proceed
     * @param int $categoryId
     * @return bool
     */
    public function aroundCanBeShowInCategory(Product $subject, callable $proceed, $categoryId)
    {
        if (!$subject instanceof ShellNoEavProduct) {
            return $proceed($categoryId);
        }

        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_SERVE_TREE, ScopeInterface::SCOPE_STORE)) {
            return $proceed($categoryId);
        }

        $ids = $subject->getData('category_ids');
        if (!is_array($ids)) {
            return $proceed($categoryId);
        }

        return in_array((int) $categoryId, array_map('intval', $ids), true);
    }
}
