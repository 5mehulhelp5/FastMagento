<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ParkkTech\FastMagento\Model\OpenSearch\InstantCategoryUpdater;

/**
 * Reproject a category into OpenSearch as soon as its save commits.
 *
 * The category twin of InstantUpdateOnSave, and the same shape deliberately: bound to the COMMIT
 * event so a rolled-back save never leaves the index ahead of the database, gated by config, and
 * best-effort so it can never block a save.
 *
 * WHY THIS BECAME NECESSARY
 * -------------------------
 * While the index only fed menus and child lists, a category document lagging until the next cron
 * was tolerable. Once category ATTRIBUTES are served from it, the page's own name, description,
 * meta tags and layout come from that document -- so an admin who edits a category and reloads
 * the storefront would keep seeing the old page until the scheduled indexer caught up. That is
 * exactly the kind of silent staleness a merchant reads as "my change didn't save".
 *
 * SCOPE
 * -----
 * Fires only for a manual admin edit -- the category form save, and the drag-and-drop tree move.
 * API writes, imports and CLI fall through to the scheduled mview indexer, matching how products
 * are handled and keeping a bulk category import from fanning out into one reprojection (plus
 * ancestors, plus subtree) per row.
 */
class InstantCategoryUpdateOnSave implements ObserverInterface
{
    private const XML_PATH_INSTANT_CATEGORY_UPDATE = 'fastmagento/indexing/instant_category_update';

    /** Admin actions that represent a human editing one category. */
    private const MANUAL_ACTIONS = [
        'catalog_category_save',
        'catalog_category_move',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly InstantCategoryUpdater $updater,
        private readonly State $appState,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_INSTANT_CATEGORY_UPDATE)) {
            return;
        }

        if (!$this->isManualAdminEdit()) {
            return;
        }

        $category = $observer->getEvent()->getCategory() ?: $observer->getEvent()->getDataObject();
        if ($category instanceof Category) {
            $this->updater->updateOnSave($category);
        }
    }

    /**
     * True only for an admin category form save or tree move. Any other area (API, frontend, cron,
     * CLI) or any other admin action returns false and defers to the scheduled indexer.
     */
    private function isManualAdminEdit(): bool
    {
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_ADMINHTML) {
                return false;
            }
        } catch (\Throwable) {
            // Area not set (a CLI context) — not a manual admin edit.
            return false;
        }

        return $this->request instanceof HttpRequest
            && in_array($this->request->getFullActionName(), self::MANUAL_ACTIONS, true);
    }
}
