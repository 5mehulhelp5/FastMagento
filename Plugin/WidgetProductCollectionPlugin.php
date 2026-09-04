<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\Link\Product\Collection as LinkProductCollection;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as FulltextCollection;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use ParkkTech\FastMagento\Model\Plp\CategoryWidgetServer;

/**
 * Hands category-widget product collections to CategoryWidgetServer at the point where the
 * native load would run its entity query. The listing (fulltext) collection and the
 * related/up-sell/cross-sell link collection have their own index paths and are skipped.
 */
class WidgetProductCollectionPlugin
{
    public function __construct(
        private readonly CategoryWidgetServer $server,
        private readonly AppState $appState
    ) {
    }

    public function around_loadEntities(ProductCollection $subject, callable $proceed, $printQuery = false, $logQuery = false)
    {
        try {
            if ($subject instanceof FulltextCollection
                || $subject instanceof LinkProductCollection
                || $this->appState->getAreaCode() !== Area::AREA_FRONTEND
                || !$this->server->isEnabled()
            ) {
                return $proceed($printQuery, $logQuery);
            }
            if ($this->server->serve($subject)) {
                return $subject;
            }
        } catch (\Throwable $e) {
            // Fall through to the native load.
        }
        return $proceed($printQuery, $logQuery);
    }
}
