<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Eav;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Eav\Model\ResourceModel\ReadHandler;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\ScopeInterface;
use ParkkTech\FastMagento\Model\OpenSearch\CategoryDataProvider;

/**
 * Fill a category's EAV attribute values from the index instead of the per-backend-type UNION.
 *
 * Loading one category costs four queries: an existence check, the entity row, this handler's
 * UNION across catalog_category_entity_{varchar,int,text,decimal,datetime}, and the model's own
 * read. The UNION is the expensive one, and every value it returns is already projected onto the
 * category document.
 *
 * WHY THIS SEAM AND NOT A SHELL CATEGORY
 * --------------------------------------
 * The obvious bigger move is to bypass CategoryRepository::get() and hand back a category built
 * entirely from the index. That was deliberately NOT done. A Category is not just data: loading
 * one runs core's afterLoad chain and the repository's own identity map, and a category drives
 * layout handles, design settings and custom layout updates -- a shell that is subtly incomplete
 * shows up as a page rendering with the wrong layout, silently, on the one category that had a
 * custom design set.
 *
 * Plugging the read handler keeps ALL of that: core still loads the entity, still runs its
 * afterLoad chain, and the repository still caches. Only the attribute-value fetch changes source.
 *
 * ALL-OR-NOTHING
 * --------------
 * Substitutes only when the document can answer for EVERY attribute the handler would have
 * returned -- FastMagento indexes all 27 non-static category attributes of a stock install, and
 * the check is made against the request, not against that assumption. A document missing even one
 * expected key falls through to the native UNION, because a category silently missing an attribute
 * is worse than a query.
 *
 * Scoped to categories: the handler is shared with products and every other EAV entity, and this
 * touches none of them.
 */
class CategoryReadHandlerPlugin
{
    private const XML_PATH_SERVE_CATEGORY_ATTRS = 'fastmagento/serving/serve_category_attributes';

    /** Top-level document fields that are attribute values rather than tree metadata. */
    private const TOP_LEVEL_ATTRIBUTES = [
        'name', 'is_active', 'include_in_menu', 'is_anchor', 'display_mode', 'url_key', 'url_path',
        'all_children',
    ];

    public function __construct(
        private readonly State $appState,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryDataProvider $categoryData
    ) {
    }

    /**
     * @param ReadHandler $subject
     * @param callable $proceed
     * @param string $entityType
     * @param array $entityData
     * @param array $arguments
     * @return array
     */
    public function aroundExecute(
        ReadHandler $subject,
        callable $proceed,
        $entityType,
        $entityData,
        $arguments = []
    ) {
        try {
            if ($entityType !== CategoryInterface::class
                || $this->appState->getAreaCode() !== Area::AREA_FRONTEND
            ) {
                return $proceed($entityType, $entityData, $arguments);
            }

            if (!$this->scopeConfig->isSetFlag(self::XML_PATH_SERVE_CATEGORY_ATTRS, ScopeInterface::SCOPE_STORE)) {
                return $proceed($entityType, $entityData, $arguments);
            }

            $id = (int) ($entityData['entity_id'] ?? 0);
            if (!$id) {
                return $proceed($entityType, $entityData, $arguments);
            }

            $doc = $this->categoryData->getById($id);
            if ($doc === null || !isset($doc['fm_attrs']) || !is_array($doc['fm_attrs'])) {
                return $proceed($entityType, $entityData, $arguments);
            }

            $values = $doc['fm_attrs'];
            foreach (self::TOP_LEVEL_ATTRIBUTES as $code) {
                if (!array_key_exists($code, $doc)) {
                    // The document predates one of these fields; do not guess.
                    return $proceed($entityType, $entityData, $arguments);
                }
                $values[$code] = $doc[$code];
            }

            foreach ($values as $code => $value) {
                // Null in the index means "this category has no value", which is what the UNION
                // expresses by returning no row -- so leave the key absent rather than writing a
                // null over whatever the entity row already carries.
                if ($value === null) {
                    continue;
                }
                $entityData[$code] = $value;
            }

            return $entityData;
        } catch (\Throwable $e) {
            return $proceed($entityType, $entityData, $arguments);
        }
    }
}
