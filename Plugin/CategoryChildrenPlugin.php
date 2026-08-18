<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\Category;

/**
 * Memoise the two child lookups Category makes per request.
 *
 * getChildrenCategories()
 * -----------------------
 *
 * It delegates straight to the resource model, which builds and loads a brand new collection every
 * call — there is no caching on either side. The layered-navigation category filter calls it twice
 * per listing render on the same category: once in apply(), to collect child ids for the
 * `category_ids_to_aggregate` filter, and again in _getItemsData(), to turn the OpenSearch facet
 * counts into filter items. Two identical fetches, each a `getChildren` id query plus a collection
 * load.
 *
 * Keyed by object, not by id: two model instances for the same category can legitimately carry
 * different store scopes, and only the instance we were handed is safe to answer for.
 */
class CategoryChildrenPlugin
{
    /** @var array<int, mixed> spl_object_id($category) => children collection */
    private array $children = [];

    /** @var array<int, bool> spl_object_id($category) => hasChildren() */
    private array $hasChildren = [];

    /**
     * @return mixed
     */
    public function aroundGetChildrenCategories(Category $subject, callable $proceed)
    {
        $key = spl_object_id($subject);
        if (!array_key_exists($key, $this->children)) {
            $this->children[$key] = $proceed();
        }

        return $this->children[$key];
    }

    /**
     * hasChildren() runs `SELECT COUNT(m.entity_id) ... catalog_category_entity` through the
     * resource model on every call, with nothing caching the answer. Catalog\Controller\Category
     * \View::execute() asks twice while deciding how to render the page, which is two identical
     * counts before a single block has rendered.
     *
     * @return bool
     */
    public function aroundHasChildren(Category $subject, callable $proceed): bool
    {
        $key = spl_object_id($subject);
        if (!array_key_exists($key, $this->hasChildren)) {
            $this->hasChildren[$key] = (bool) $proceed();
        }

        return $this->hasChildren[$key];
    }
}
