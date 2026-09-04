<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Db;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Open Source vs Commerce (Adobe Commerce / Magento EE) catalogue schema, in one place.
 *
 * Commerce's content staging rewrites the catalogue schema: catalog_product_entity and
 * catalog_category_entity gain a `row_id` primary key (one row per scheduled version, several
 * rows can share an `entity_id`), and every child table that used to reference `entity_id`
 * references `row_id` instead:
 *
 *   product:  catalog_product_entity_{varchar,int,decimal,datetime,text}.row_id,
 *             catalog_product_entity_tier_price.row_id,
 *             catalog_product_entity_media_gallery_value(.row_id) and _value_to_entity.row_id,
 *             catalog_product_option.product_id, catalog_product_super_attribute.product_id,
 *             catalog_product_super_link.parent_id, catalog_product_relation.parent_id,
 *             catalog_product_link.product_id (linked_product_id stays an entity id),
 *             catalog_product_bundle_option.parent_id,
 *             catalog_product_bundle_selection.parent_product_id (product_id stays an entity id)
 *   category: catalog_category_entity_{varchar,int,decimal,datetime,text}.row_id
 *
 * Everything else keeps entity ids: catalog_category_product, catalog_product_website,
 * catalog_product_index_*, catalogrule_product(_price), cataloginventory_*, url_rewrite,
 * review (entity_pk_value), sales_order_item.product_id, wishlist_item.product_id.
 *
 * Magento exposes the difference through MetadataPool: getLinkField() is `row_id` on Commerce
 * and `entity_id` on Open Source. Every raw select in this extension that touches one of the
 * tables above goes through this class, so the query is written ONCE and is correct on both.
 * On Open Source the helpers add nothing (no join, the same column), so the queries — and the
 * query counts this extension is measured by — are unchanged there.
 *
 * Version filtering (created_in / updated_in) is not this class's concern: Commerce applies it
 * to every select on a staged entity table through its FromRenderer plugin, so joining the
 * entity table already yields the currently active version only.
 */
class EntityLink
{
    private ?string $productLink = null;
    private ?string $categoryLink = null;

    public function __construct(
        private readonly MetadataPool $metadataPool,
        private readonly ResourceConnection $resource
    ) {
    }

    /** `row_id` on Commerce, `entity_id` on Open Source. */
    public function productLinkField(): string
    {
        return $this->productLink ??= $this->linkField(ProductInterface::class);
    }

    /** `row_id` on Commerce, `entity_id` on Open Source. */
    public function categoryLinkField(): string
    {
        return $this->categoryLink ??= $this->linkField(CategoryInterface::class);
    }

    public function isProductStaged(): bool
    {
        return $this->productLinkField() !== 'entity_id';
    }

    public function isCategoryStaged(): bool
    {
        return $this->categoryLinkField() !== 'entity_id';
    }

    /**
     * The SQL expression that yields the product ENTITY id for rows of a child-table alias.
     *
     * $childColumn is the child table's column that holds the link: the link field itself for
     * EAV value / tier price / gallery tables (default), or the parent-side column for link
     * tables (`parent_id` on super_link and relation, `product_id` on catalog_product_link,
     * `parent_product_id` on bundle_selection).
     *
     * Open Source: returns "$childAlias.$childColumn" and touches nothing.
     * Commerce: joins catalog_product_entity once per alias (as "{$childAlias}_e") on row_id and
     * returns "{$childAlias}_e.entity_id". Use the expression both in columns() and in where().
     */
    public function productEntityId(Select $select, string $childAlias, ?string $childColumn = null): string
    {
        return $this->entityIdExpr(
            $select,
            $childAlias,
            $childColumn ?? $this->productLinkField(),
            $this->productLinkField(),
            'catalog_product_entity'
        );
    }

    /**
     * Same as productEntityId() for category child tables (catalog_category_entity_*).
     */
    public function categoryEntityId(Select $select, string $childAlias, ?string $childColumn = null): string
    {
        return $this->entityIdExpr(
            $select,
            $childAlias,
            $childColumn ?? $this->categoryLinkField(),
            $this->categoryLinkField(),
            'catalog_category_entity'
        );
    }

    /**
     * The join condition to reach a product child table from an already-joined
     * catalog_product_entity alias: "$entityAlias.<link> = $childAlias.$childColumn".
     * Use when the select already has the entity table (so no second join is wanted).
     */
    public function productChildJoin(string $entityAlias, string $childAlias, ?string $childColumn = null): string
    {
        $link = $this->productLinkField();

        return sprintf('%s.%s = %s.%s', $entityAlias, $link, $childAlias, $childColumn ?? $link);
    }

    public function categoryChildJoin(string $entityAlias, string $childAlias, ?string $childColumn = null): string
    {
        $link = $this->categoryLinkField();

        return sprintf('%s.%s = %s.%s', $entityAlias, $link, $childAlias, $childColumn ?? $link);
    }

    private function entityIdExpr(
        Select $select,
        string $childAlias,
        string $childColumn,
        string $link,
        string $entityTable
    ): string {
        if ($link === 'entity_id') {
            return $childAlias . '.' . $childColumn;
        }
        $entityAlias = $childAlias . '_e';
        $from = $select->getPart(Select::FROM);
        if (!isset($from[$entityAlias])) {
            $select->join(
                [$entityAlias => $this->resource->getTableName($entityTable)],
                sprintf('%s.%s = %s.%s', $entityAlias, $link, $childAlias, $childColumn),
                []
            );
        }

        return $entityAlias . '.entity_id';
    }

    private function linkField(string $entityType): string
    {
        try {
            return (string) $this->metadataPool->getMetadata($entityType)->getLinkField();
        } catch (\Throwable $e) {
            return 'entity_id';
        }
    }
}
