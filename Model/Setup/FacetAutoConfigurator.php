<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Setup;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Psr\Log\LoggerInterface;

/**
 * Self-heal the "no facet attributes anywhere" state on a fresh install.
 *
 * The facet auto-detector only considers attributes flagged "Use in Search Results Layered
 * Navigation" (`is_filterable_in_search`). Plenty of real installs flag attributes filterable for
 * CATEGORY layered navigation (`is_filterable`) and never touch the search variant — the admin
 * checkbox is three screens deep — leaving doctor's FACETS group warning "None configured and none
 * auto-detected" and the search grid with no facets at all.
 *
 * When (and only when) the store has NO explicit facet config and NOTHING auto-detects, this
 * mirrors the merchant's already-expressed intent: every select/multiselect product attribute the
 * merchant flagged filterable for category navigation gets `is_filterable_in_search = 1` too. It
 * then clears the detector's cache and invalidates the fulltext + FastMagento indexers so the next
 * (cron or manual) reindex projects the option data.
 *
 * Runs from Setup/RecurringData (every `setup:upgrade`, so sample data installed later is picked
 * up on the next deploy) and from `fastmagento:doctor --fix`. Deliberately writes the flag with a
 * direct set-based UPDATE rather than per-attribute model saves: the guard guarantees this runs at
 * most once per store lifetime, the flag is a projection input (not EAV data), and setup must not
 * fan out into per-attribute save observers.
 */
class FacetAutoConfigurator
{
    private const FACET_CONFIG_PATH = 'fastmagento/search/facet_attributes';
    private const CANDIDATES_CACHE_KEY = 'fastmagento_facet_attribute_candidates';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly IndexerInterfaceFactory $indexerFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Mirror `is_filterable` onto `is_filterable_in_search` when the store has no facet source at
     * all. Returns the attribute codes changed ([] when the guard says nothing needed doing).
     *
     * @return string[]
     */
    public function mirrorIfUnconfigured(): array
    {
        // Guard 1: an explicit facet config means the merchant already decided — never touch.
        $configured = trim((string) $this->scopeConfig->getValue(self::FACET_CONFIG_PATH));
        if ($configured !== '') {
            return [];
        }

        $connection = $this->resource->getConnection();
        $eavAttribute = $this->resource->getTableName('eav_attribute');
        $catalogEav = $this->resource->getTableName('catalog_eav_attribute');
        $entityType = $this->resource->getTableName('eav_entity_type');

        // Guard 2: anything already flagged for search layered nav means auto-detect works — done.
        $already = (int) $connection->fetchOne(
            $connection->select()
                ->from(['a' => $eavAttribute], 'COUNT(*)')
                ->join(['c' => $catalogEav], 'c.attribute_id = a.attribute_id', [])
                ->join(['t' => $entityType], 't.entity_type_id = a.entity_type_id', [])
                ->where('t.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY)
                ->where("a.frontend_input IN ('select', 'multiselect')")
                ->where('c.is_filterable_in_search = 1')
        );
        if ($already > 0) {
            return [];
        }

        // Candidates: merchant flagged them filterable for category layered navigation.
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['a' => $eavAttribute], ['attribute_id', 'attribute_code'])
                ->join(['c' => $catalogEav], 'c.attribute_id = a.attribute_id', [])
                ->join(['t' => $entityType], 't.entity_type_id = a.entity_type_id', [])
                ->where('t.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY)
                ->where("a.frontend_input IN ('select', 'multiselect')")
                ->where('c.is_filterable > 0')
        );
        if (!$rows) {
            return []; // nothing to mirror from — the doctor warning stands, with its manual fix
        }

        $ids = array_map(static fn (array $r) => (int) $r['attribute_id'], $rows);
        $codes = array_map(static fn (array $r) => (string) $r['attribute_code'], $rows);

        $connection->update(
            $catalogEav,
            ['is_filterable_in_search' => 1],
            ['attribute_id IN (?)' => $ids]
        );

        // The detector caches its candidate list; drop it so doctor/admin see the change now.
        $this->cache->remove(self::CANDIDATES_CACHE_KEY);
        $this->cache->clean([\Magento\Eav\Model\Cache\Type::CACHE_TAG]);

        foreach (['catalogsearch_fulltext', 'fastmagento_product', 'fastmagento_attribute_option'] as $indexerId) {
            try {
                $this->indexerFactory->create()->load($indexerId)->invalidate();
            } catch (\Throwable $e) {
                // An absent indexer (e.g. different engine setup) must not fail setup.
            }
        }

        $this->logger->info(sprintf(
            '[FastMagento] Auto-enabled "Use in Search Results Layered Navigation" for %s — '
            . 'mirrored from the category layered-navigation flag because no attribute was usable '
            . 'as a search facet. Indexers invalidated; next reindex projects the option data.',
            implode(', ', $codes)
        ));

        return $codes;
    }
}
