<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\ResourceModel\Fulltext;

use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as CoreFulltextCollection;
use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\Plp\ListingHydrator;

/**
 * Product listing collection that hydrates its items from OpenSearch instead of EAV.
 *
 * WHY A PREFERENCE AND NOT A PLUGIN
 * ---------------------------------
 * The only useful seam is `_loadEntities()` — the step that turns the ids the search engine
 * already returned into product objects. It is declared public on the core class, but Magento's
 * interception code generator skips every method whose name starts with `_`, so the generated
 * `Collection\Interceptor` wraps only `load()` and `getCurPage()`. A plugin on `_loadEntities`
 * is silently never called. Overriding it requires replacing the class.
 *
 * The preference is registered in `etc/frontend/di.xml` only, so the admin, CLI and integration
 * code keep the core collection untouched.
 *
 * Note for integrators: if another extension also sets a preference for this class, the two will
 * conflict (last one wins, as with any preference). `bin/magento fastmagento:doctor` reports which
 * class is actually in use so the clash is visible rather than mysterious.
 */
class Collection extends CoreFulltextCollection
{
    private ?ListingHydrator $fastMagentoHydrator = null;
    private ?State $fastMagentoAppState = null;

    /**
     * Populate the collection for the current page.
     *
     * By this point the engine has already supplied the ids, their order, the page limit and the
     * layered-navigation facet counts — everything except the product data itself. When the whole
     * page can be served from the index we fill it here and skip the EAV pass entirely; otherwise
     * we defer to core, which is also what happens on any miss, any error, and in the admin.
     *
     * @param bool $printQuery
     * @param bool $logQuery
     * @return $this
     */
    public function _loadEntities($printQuery = false, $logQuery = false)
    {
        if ($this->fastMagentoCanServe() && $this->fastMagentoGetHydrator()->hydrate($this)) {
            return $this;
        }

        return parent::_loadEntities($printQuery, $logQuery);
    }

    private function fastMagentoCanServe(): bool
    {
        try {
            if ($this->fastMagentoGetAppState()->getAreaCode() !== Area::AREA_FRONTEND) {
                return false;
            }
        } catch (\Throwable $e) {
            // Area not set yet — never take over.
            return false;
        }

        return $this->fastMagentoGetHydrator()->isEnabled();
    }

    /**
     * Resolved lazily rather than through the constructor: the parent signature carries ~35
     * arguments and is extended by other modules, so adding to it is a compatibility hazard for
     * no benefit — these are only needed on the frontend load path.
     */
    private function fastMagentoGetHydrator(): ListingHydrator
    {
        if ($this->fastMagentoHydrator === null) {
            $this->fastMagentoHydrator = ObjectManager::getInstance()->get(ListingHydrator::class);
        }
        return $this->fastMagentoHydrator;
    }

    private function fastMagentoGetAppState(): State
    {
        if ($this->fastMagentoAppState === null) {
            $this->fastMagentoAppState = ObjectManager::getInstance()->get(State::class);
        }
        return $this->fastMagentoAppState;
    }
}
