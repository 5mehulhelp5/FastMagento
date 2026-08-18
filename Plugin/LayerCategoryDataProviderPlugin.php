<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\Layer\Filter\DataProvider\Category as CategoryDataProvider;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Framework\Registry;

/**
 * Stop the layered-navigation category filter re-loading the category the controller already
 * loaded.
 *
 * `CatalogSearch\Model\Layer\Filter\Category::apply()` reads the category id straight off the
 * request — on a category page that is the `id` route param, i.e. the page's own category — and
 * hands it to this data provider, which unconditionally builds a fresh model and load()s it:
 *
 *     $category = $this->categoryFactory->create()->setStoreId(...)->load($this->categoryId);
 *
 * That is four queries (the entity row, the EntityManager's ReadMain and ReadAttributes, and a
 * CheckIfExists probe) for a category already sitting fully loaded in the registry, put there by
 * Catalog\Controller\Category\View a moment earlier. Only when the loaded model comes back empty
 * does core fall through to getCurrentCategory() — the object we could have used all along.
 *
 * So: when the requested id IS the current category, hand back the loaded one. Any other id (a
 * `?cat=` drill-down into a child) still loads natively, because that genuinely is a different
 * category.
 *
 * Serving the same object also lets {@see CategoryChildrenPlugin} memoise getChildrenCategories()
 * across apply() and _getItemsData(), which otherwise fetch the identical child set twice.
 */
class LayerCategoryDataProviderPlugin
{
    /** @var array<int, int> spl_object_id($dataProvider) => requested category id */
    private array $requested = [];

    public function __construct(
        private readonly Resolver $layerResolver,
        private readonly Registry $registry
    ) {
    }

    /**
     * The id core is about to resolve. Keyed per data-provider instance: the filter list builds one
     * provider per filter via a factory, and a plugin instance is shared across all of them.
     *
     * @param int|string $categoryId
     * @return null
     */
    public function beforeSetCategoryId(CategoryDataProvider $subject, $categoryId)
    {
        $this->requested[spl_object_id($subject)] = (int) $categoryId;

        return null;
    }

    /**
     * @return \Magento\Catalog\Model\Category
     */
    public function aroundGetCategory(CategoryDataProvider $subject, callable $proceed)
    {
        $requested = $this->requested[spl_object_id($subject)] ?? null;
        if ($requested === null || $requested <= 0) {
            return $proceed();
        }

        try {
            $current = $this->layerResolver->get()->getCurrentCategory();
        } catch (\Throwable $e) {
            return $proceed();
        }

        // Not the page's own category (a drill-down), or nothing loaded yet: let core load it.
        if (!$current || (int) $current->getId() !== $requested) {
            return $proceed();
        }

        // Core registers this from getCategory(); templates and other filters read it, so it has to
        // be set on this path too.
        $this->registry->unregister('current_category_filter');
        $this->registry->register('current_category_filter', $current, true);

        return $current;
    }
}
