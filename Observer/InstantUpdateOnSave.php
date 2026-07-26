<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Model\OpenSearch\InstantProductUpdater;

/**
 * Once a product save commits, when Instant Product Update is enabled, reproject the product into
 * OpenSearch and purge its full-page cache immediately - so an admin/staff edit is live on the
 * storefront at once instead of after the scheduled indexer + cache lag. Bound to
 * catalog_product_save_commit_after. Gated + best-effort; never blocks a save.
 *
 * SCOPE - instant update fires ONLY for a manual single-product edit in the admin
 * (area `adminhtml`, full action name `catalog_product_save`). Everything else deliberately
 * falls through to the scheduled mview indexer ("core rules"):
 *   - REST/GraphQL API writes (area webapi_rest / graphql),
 *   - admin bulk paths - mass status/delete, "Update attributes"
 *     (catalog_product_action_attribute_save), CSV import,
 *   - cron / CLI reindex.
 * This keeps a bulk of 10k saves off the per-save reprojection+purge cost (which would otherwise
 * fire once per row) while a human editing one product still sees it live immediately.
 */
class InstantUpdateOnSave implements ObserverInterface
{
    /** Admin single-product edit form (catalog/product/save). Bulk/mass actions use other actions. */
    private const SINGLE_EDIT_ACTION = 'catalog_product_save';

    public function __construct(
        private readonly OpenSearchConfig $config,
        private readonly InstantProductUpdater $updater,
        private readonly State $appState,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isInstantProductUpdateEnabled()) {
            return;
        }

        // Only a manual single-product admin edit is served instantly; API + bulk edits go
        // through the normal scheduled indexer so a mass save never fans out into N reprojections.
        if (!$this->isManualAdminSingleEdit()) {
            return;
        }

        $product = $observer->getEvent()->getProduct();
        if ($product instanceof Product) {
            $this->updater->updateOnSave($product);
        }
    }

    /**
     * True only for the admin single-product edit form save. Any non-admin area (API/frontend/cron)
     * or admin bulk action (different full action name) returns false -> scheduled mview indexer.
     */
    private function isManualAdminSingleEdit(): bool
    {
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_ADMINHTML) {
                return false;
            }
        } catch (\Throwable) {
            // Area not set (e.g. a CLI context) - not a manual admin edit.
            return false;
        }

        return $this->request instanceof HttpRequest
            && $this->request->getFullActionName() === self::SINGLE_EDIT_ACTION;
    }
}
