<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use ParkkTech\FastMagento\Model\OpenSearch\ParentIdResolver;

/**
 * Serve Grouped::getParentIdsByChild() from OpenSearch on the frontend and graphql areas.
 *
 * Product-slider widgets call getParentIdsByChild() per product for BOTH the configurable
 * and grouped types; the grouped call is a catalog_product_link query, so a slider over N
 * products adds N such queries. The configurable half is handled by ConfigurableProductType;
 * this covers the grouped half through the same shared ParentIdResolver, so both types cost
 * a single OpenSearch fetch per child.
 *
 * Grouped parent ids are not separately indexed, so a child that exists in OpenSearch is
 * treated as having no grouped parent (correct for a catalog without grouped products) and
 * the DB query is skipped. Children absent from OpenSearch fall back to the native lookup.
 */
class GroupedParentIdPlugin
{
    /** OS-serve on the customer-facing storefront (frontend) and headless GraphQL areas. */
    private const SERVABLE_AREAS = [Area::AREA_FRONTEND, Area::AREA_GRAPHQL];

    public function __construct(
        private readonly State $appState,
        private readonly ParentIdResolver $parentIdResolver
    ) {
    }

    /**
     * @param Grouped $subject
     * @param callable $proceed
     * @param int $childId
     * @return int[]
     */
    public function aroundGetParentIdsByChild(Grouped $subject, callable $proceed, $childId)
    {
        try {
            if (!in_array($this->appState->getAreaCode(), self::SERVABLE_AREAS, true)) {
                return $proceed($childId);
            }
            if ($this->parentIdResolver->getOsParentIds((int)$childId) !== null) {
                return [];
            }
        } catch (\Throwable $e) {
            // fall through to native lookup
        }
        return $proceed($childId);
    }
}
