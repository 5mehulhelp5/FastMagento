<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Doctor;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Model\Config\Source\FacetAttributes;
use ParkkTech\FastMagento\Model\OpenSearch\IndexSettings;
use ParkkTech\FastMagento\Model\OptionDictionary;
use ParkkTech\FastMagento\Setup\Patch\Data\InitializeIndexers;

/**
 * Everything that has to be true for FastMagento to actually serve, checked in one pass.
 *
 * This exists because of a single recurring support shape: FastMagento's failure modes do not
 * throw. A missing index, an unbuilt option dictionary, an index prefix shared with another
 * store, a facet attribute Magento will not aggregate, a theme with no RequireJS — every one of
 * those leaves the storefront returning HTTP 200 with a feature quietly missing. Each check below
 * corresponds to a real failure observed on a real install.
 *
 * Read-only: it inspects and reports, never repairs.
 */
class Diagnostics
{
    private const G_CLUSTER = 'Cluster';
    private const G_INDEX = 'Indices';
    private const G_INDEXER = 'Indexers';
    private const G_CRON = 'Cron';
    private const G_FACETS = 'Facets';
    private const G_THEME = 'Theme';
    private const G_CHECKOUT = 'Checkout';
    private const G_PHP = 'PHP';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource,
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly IndexerInterfaceFactory $indexerFactory,
        private readonly FacetAttributes $facetAttributes,
        private readonly OptionDictionary $optionDictionary,
        private readonly IndexSettings $indexSettings,
        private readonly \Magento\Framework\Module\Manager $moduleManager,
        private readonly \Magento\Framework\View\DesignInterface $design,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Magento\Framework\View\Design\Theme\ThemeProviderInterface $themeProvider
    ) {
    }

    /**
     * Active frontend theme path (e.g. "Hyva/default"), resolved for EVERY store view.
     *
     * DesignInterface::getDesignTheme() returns an unpopulated theme under CLI — nothing has
     * loaded a frontend design for a request that does not exist — so reading it there yields an
     * empty name. Going through the stored design configuration instead gives the same answer the
     * storefront would, and doing it per store view matters because the theme is a store-scoped
     * setting: one view on Hyvä and another on Luma is a supported (and easily missed) setup.
     *
     * @return array<string, string> store code => theme path
     */
    private function resolveFrontendThemes(): array
    {
        $themes = [];

        foreach ($this->storeManager->getStores() as $store) {
            try {
                $identifier = $this->design->getConfigurationDesignTheme(
                    \Magento\Framework\App\Area::AREA_FRONTEND,
                    ['store' => $store->getId()]
                );
                if (!$identifier) {
                    continue;
                }
                $theme = is_numeric($identifier)
                    ? $this->themeProvider->getThemeById((int) $identifier)
                    : $this->themeProvider->getThemeByFullPath('frontend/' . $identifier);

                $path = $theme ? (string) $theme->getThemePath() : (string) $identifier;
                if ($path !== '') {
                    $themes[(string) $store->getCode()] = $path;
                }
            } catch (\Throwable $e) {
                // a single unresolvable store view must not sink the whole report
            }
        }

        return $themes;
    }

    /**
     * @return Check[]
     */
    public function run(): array
    {
        $checks = [];
        $client = $this->resolveClient();

        $checks = array_merge($checks, $this->checkCluster($client));
        $checks = array_merge($checks, $this->checkIndices($client));
        $checks = array_merge($checks, $this->checkIndexers());
        $checks = array_merge($checks, $this->checkCron());
        $checks = array_merge($checks, $this->checkFacets($client));
        $checks = array_merge($checks, $this->checkTheme());
        $checks = array_merge($checks, $this->checkCheckout());
        $checks = array_merge($checks, $this->checkPhp());

        return $checks;
    }

    private function resolveClient()
    {
        try {
            return $this->clientResolver
                ->create($this->engineResolver->getCurrentSearchEngine())
                ->getOpenSearchClient();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return Check[]
     */
    private function checkCluster($client): array
    {
        $out = [];
        $engine = (string) $this->scopeConfig->getValue('catalog/search/engine');
        $host = (string) $this->scopeConfig->getValue('catalog/search/opensearch_server_hostname');
        $port = (string) $this->scopeConfig->getValue('catalog/search/opensearch_server_port');

        if (stripos($engine, 'opensearch') === false && stripos($engine, 'elastic') === false) {
            $out[] = Check::fail(
                self::G_CLUSTER,
                'Search engine',
                sprintf('catalog/search/engine is "%s"', $engine !== '' ? $engine : '(unset)'),
                'FastMagento serves from OpenSearch/Elasticsearch. Set Stores > Configuration > '
                . 'Catalog > Catalog > Catalog Search > Search Engine.'
            );
            return $out;
        }

        if (!$client) {
            $out[] = Check::fail(
                self::G_CLUSTER,
                'Connection',
                sprintf('Could not build a search client for engine "%s" (%s:%s)', $engine, $host, $port),
                'Check the host/port under Catalog Search and that the cluster is reachable from '
                . 'this server: curl -s ' . ($host ?: 'localhost') . ':' . ($port ?: '9200')
            );
            return $out;
        }

        try {
            $health = $client->cluster()->health();
            $status = (string) ($health['status'] ?? 'unknown');
            $detail = sprintf(
                'engine=%s host=%s:%s status=%s nodes=%s',
                $engine,
                $host,
                $port,
                $status,
                (string) ($health['number_of_nodes'] ?? '?')
            );
            // A single-node cluster is permanently yellow (replicas unassigned). That is normal
            // and must not be reported as a problem, or every dev box fails its own health check.
            $out[] = $status === 'red'
                ? Check::fail(self::G_CLUSTER, 'Cluster health', $detail, 'Cluster is RED — shards are unavailable. Fix the cluster before reindexing.')
                : Check::ok(self::G_CLUSTER, 'Cluster health', $detail . ($status === 'yellow' ? ' (yellow is normal on a single node)' : ''));
        } catch (\Throwable $e) {
            $out[] = Check::fail(self::G_CLUSTER, 'Cluster health', $e->getMessage(), 'Verify the cluster is up and reachable.');
        }

        // Index-prefix collision. Two installs sharing one cluster on the default "magento2"
        // prefix silently overwrite each other's indices — observed on a real dev machine.
        $prefix = (string) $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix');
        if ($prefix === '' || $prefix === 'magento2') {
            $out[] = Check::warn(
                self::G_CLUSTER,
                'Index prefix',
                sprintf('Using the default prefix "%s"', $prefix !== '' ? $prefix : 'magento2'),
                'If any other Magento install shares this cluster, both write the same index names '
                . 'and destroy each other on reindex. Set a unique prefix per install under '
                . 'Catalog Search > OpenSearch Index Prefix, then reindex.'
            );
        } else {
            $out[] = Check::ok(self::G_CLUSTER, 'Index prefix', sprintf('"%s"', $prefix));
        }

        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkIndices($client): array
    {
        $out = [];
        if (!$client) {
            return [Check::skip(self::G_INDEX, 'Indices', 'No search client — see Cluster above.')];
        }

        $productCount = (int) $this->resource->getConnection()->fetchOne(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName('catalog_product_entity'), 'COUNT(*)')
        );

        $indices = [
            'product serving index' => [$this->openSearchConfig->getIndexName(), $productCount],
            'category serving index' => [$this->openSearchConfig->getCategoryIndexName(), null],
            'attribute option dictionary' => [$this->openSearchConfig->getAttributeOptionIndexName(), null],
        ];

        foreach ($indices as $label => [$indexName, $expected]) {
            if (!$indexName) {
                continue;
            }
            try {
                $stats = $client->count(['index' => $indexName]);
                $docs = (int) ($stats['count'] ?? 0);

                if ($docs === 0) {
                    $out[] = Check::fail(
                        self::G_INDEX,
                        $label,
                        sprintf('%s is EMPTY', $indexName),
                        'Run: bin/magento indexer:reindex ' . implode(' ', InitializeIndexers::INDEXERS)
                    );
                    continue;
                }

                // A serving index far below the catalogue size is the signature of the silent
                // partial-index bug: bulk items rejected per-item while the reindex "succeeded".
                if ($expected !== null && $expected > 0 && $docs < ($expected * 0.9)) {
                    $out[] = Check::warn(
                        self::G_INDEX,
                        $label,
                        sprintf('%s holds %d docs but the catalogue has %d products', $indexName, $docs, $expected),
                        'Documents were likely rejected during bulk indexing. Reindex and read the '
                        . 'error; if it mentions "Limit of total fields", raise FastMagento > '
                        . 'Indexing > OpenSearch Field Limit.'
                    );
                    continue;
                }

                $out[] = Check::ok(self::G_INDEX, $label, sprintf('%s (%d docs)', $indexName, $docs));
            } catch (\Throwable $e) {
                $out[] = Check::fail(
                    self::G_INDEX,
                    $label,
                    sprintf('%s is missing or unreadable: %s', $indexName, $e->getMessage()),
                    'Run: bin/magento indexer:reindex ' . implode(' ', InitializeIndexers::INDEXERS)
                );
            }
        }

        // Mapping headroom — the check that would have caught the silent truncation up front.
        try {
            $indexName = $this->openSearchConfig->getIndexName();
            $configured = $this->indexSettings->getTotalFieldsLimit();
            $settings = $client->indices()->getSettings(['index' => $indexName]);
            $node = reset($settings);
            $effective = (int) ($node['settings']['index']['mapping']['total_fields']['limit'] ?? 1000);
            $mapping = $client->indices()->getMapping(['index' => $indexName]);
            $mapNode = reset($mapping);
            $used = $this->countFields($mapNode['mappings'] ?? []);

            $detail = sprintf('%d of %d fields mapped (module setting: %d)', $used, $effective, $configured);
            if ($effective > 0 && $used >= $effective * 0.9) {
                $out[] = Check::warn(
                    self::G_INDEX,
                    'Mapping headroom',
                    $detail,
                    'Close to the ceiling — raise FastMagento > Indexing > OpenSearch Field Limit '
                    . 'and reindex before it starts rejecting documents.'
                );
            } elseif ($effective < $configured) {
                $out[] = Check::warn(
                    self::G_INDEX,
                    'Mapping headroom',
                    $detail,
                    'The live index was created before the field limit was configured. '
                    . 'Reindex to recreate it with the configured limit.'
                );
            } else {
                $out[] = Check::ok(self::G_INDEX, 'Mapping headroom', $detail);
            }
        } catch (\Throwable $e) {
            $out[] = Check::skip(self::G_INDEX, 'Mapping headroom', $e->getMessage());
        }

        return $out;
    }

    /**
     * Count leaf fields in a mapping, which is what total_fields.limit actually counts.
     *
     * @param array<string, mixed> $mapping
     */
    private function countFields(array $mapping): int
    {
        $count = 0;
        $properties = $mapping['properties'] ?? [];
        foreach ($properties as $definition) {
            $count++;
            if (is_array($definition)) {
                if (isset($definition['properties'])) {
                    $count += $this->countFields($definition);
                }
                if (isset($definition['fields']) && is_array($definition['fields'])) {
                    $count += count($definition['fields']);
                }
            }
        }
        return $count;
    }

    /**
     * @return Check[]
     */
    private function checkIndexers(): array
    {
        $out = [];
        foreach (InitializeIndexers::INDEXERS as $indexerId) {
            try {
                $indexer = $this->indexerFactory->create()->load($indexerId);
                $valid = !$indexer->isInvalid();
                $scheduled = $indexer->isScheduled();
                $detail = sprintf(
                    'status=%s mode=%s',
                    $indexer->getStatus(),
                    $scheduled ? 'Update by Schedule' : 'Update on Save'
                );

                if (!$valid) {
                    $out[] = Check::fail(
                        self::G_INDEXER,
                        $indexerId,
                        $detail,
                        'Run: bin/magento indexer:reindex ' . $indexerId
                    );
                } elseif (!$scheduled) {
                    $out[] = Check::warn(
                        self::G_INDEXER,
                        $indexerId,
                        $detail,
                        'This module ships etc/mview.xml for schedule mode; on Update on Save every '
                        . 'product save reprojects synchronously. Run: bin/magento indexer:set-mode '
                        . 'schedule ' . $indexerId
                    );
                } else {
                    $out[] = Check::ok(self::G_INDEXER, $indexerId, $detail);
                }
            } catch (\Throwable $e) {
                $out[] = Check::fail(
                    self::G_INDEXER,
                    $indexerId,
                    'Not registered: ' . $e->getMessage(),
                    'Run: bin/magento setup:upgrade'
                );
            }
        }
        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkCron(): array
    {
        // Schedule mode is worthless without a running cron: the mview backlog never drains and
        // the index goes stale while the storefront keeps serving old data.
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName('cron_schedule'), ['executed_at'])
                ->where('job_code = ?', 'indexer_update_all_views')
                ->where('status = ?', 'success')
                ->order('executed_at DESC')
                ->limit(1);
            $last = $connection->fetchOne($select);

            if (!$last) {
                return [Check::fail(
                    self::G_CRON,
                    'Indexer cron',
                    'No successful indexer_update_all_views run on record',
                    'Scheduled indexers will never update. Install Magento cron: '
                    . '* * * * * php bin/magento cron:run'
                )];
            }

            $ageMinutes = (int) round((time() - strtotime((string) $last)) / 60);
            $detail = sprintf('last success %s (%d min ago)', $last, $ageMinutes);

            return [$ageMinutes > 30
                ? Check::warn(self::G_CRON, 'Indexer cron', $detail, 'Cron looks stalled — scheduled reindexes are not running.')
                : Check::ok(self::G_CRON, 'Indexer cron', $detail)];
        } catch (\Throwable $e) {
            return [Check::skip(self::G_CRON, 'Indexer cron', $e->getMessage())];
        }
    }

    /**
     * @return Check[]
     */
    private function checkFacets($client): array
    {
        $out = [];
        $configured = trim((string) $this->scopeConfig->getValue('fastmagento/search/facet_attributes'));
        $codes = $configured === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $configured))));

        if ($codes === []) {
            $auto = $this->facetAttributes->getAutoDetectedCodes();
            $out[] = $auto
                ? Check::ok(self::G_FACETS, 'Facet attributes', 'auto-detected: ' . implode(', ', $auto))
                : Check::warn(
                    self::G_FACETS,
                    'Facet attributes',
                    'None configured and none auto-detected',
                    'No product attribute is flagged "Use in Search Results Layered Navigation". '
                    . 'Set that flag on the attributes you want as facets (Stores > Attributes > '
                    . 'Product > [attribute] > Storefront Properties), then run: '
                    . 'bin/magento indexer:reindex catalogsearch_fulltext'
                );
        } else {
            $problems = $this->facetAttributes->findUnusable($codes);
            if ($problems) {
                foreach ($problems as $code => $reason) {
                    $out[] = Check::fail(
                        self::G_FACETS,
                        sprintf('Facet "%s"', $code),
                        $reason,
                        'This facet will render nothing until fixed. Clear the setting entirely to '
                        . 'auto-detect usable attributes instead.'
                    );
                }
            } else {
                $out[] = Check::ok(self::G_FACETS, 'Facet attributes', 'configured: ' . implode(', ', $codes));
            }
        }

        // The dictionary is what makes labels resolvable on a configurable catalogue. Without it
        // every attribute facet is dropped for want of a label — silently, before this check.
        try {
            $map = $this->optionDictionary->getAttributeIdsByCode();
            $out[] = $map
                ? Check::ok(self::G_FACETS, 'Option dictionary', sprintf('%d attribute(s) with resolvable labels', count($map)))
                : Check::fail(
                    self::G_FACETS,
                    'Option dictionary',
                    'Empty or unreadable',
                    'Attribute facets cannot be labelled and will be dropped. Run: '
                    . 'bin/magento indexer:reindex fastmagento_attribute_option'
                );
        } catch (\Throwable $e) {
            $out[] = Check::fail(self::G_FACETS, 'Option dictionary', $e->getMessage(), 'Run: bin/magento indexer:reindex fastmagento_attribute_option');
        }

        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkTheme(): array
    {
        $out = [];
        $takeover = $this->scopeConfig->isSetFlag('fastmagento/search/instant_search_enabled');
        $themes = $this->resolveFrontendThemes();

        if (!$themes) {
            $out[] = Check::skip(self::G_THEME, 'Active theme', 'Could not resolve a frontend theme for any store view.');
        } else {
            foreach ($themes as $storeCode => $path) {
                $flavour = match (true) {
                    stripos($path, 'hyva') !== false => ' [Hyvä]',
                    stripos($path, 'breeze') !== false => ' [Breeze]',
                    default => '',
                };
                // The storefront bundle has no jQuery/RequireJS/Alpine dependency, so there is no
                // longer an unsupported theme — but naming what was detected is what turns "it
                // doesn't work" into a five-second answer.
                $out[] = Check::ok(
                    self::G_THEME,
                    sprintf('Theme (store: %s)', $storeCode),
                    sprintf('%s%s — storefront JS is dependency-free', $path, $flavour)
                );
            }
        }

        $out[] = $takeover
            ? Check::ok(self::G_THEME, 'Instant search takeover', 'enabled — native search results are replaced by the OpenSearch grid')
            : Check::warn(
                self::G_THEME,
                'Instant search takeover',
                'disabled — native Magento search is in use',
                'Set FastMagento > Instant Search & Relevance > Enable Instant Search Results to Yes '
                . 'to serve the results page from OpenSearch.'
            );

        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkCheckout(): array
    {
        if (!$this->moduleManager->isEnabled('ParkkTech_FastMagentoCheckout')) {
            return [Check::skip(self::G_CHECKOUT, 'FastMagento Checkout', 'not installed')];
        }

        $out = [];
        // Ships disabled on purpose — but "installed and nothing happened" is the #1 confusion.
        $enabled = $this->scopeConfig->isSetFlag('fastmagentocheckout/general/enabled');
        $out[] = $enabled
            ? Check::ok(self::G_CHECKOUT, 'Fast checkout', 'enabled')
            : Check::warn(
                self::G_CHECKOUT,
                'Fast checkout',
                'Installed but NOT enabled (this is the shipped default)',
                'The module never activates itself. After QA, set FastMagento Checkout > General > '
                . 'Enabled to Yes, or run: bin/magento config:set fastmagentocheckout/general/enabled 1'
            );

        // It renders through the Luma/Knockout checkout.root block, which Hyvä's default theme
        // does not have. Without the Luma-checkout fallback the module is simply inert — the
        // checkout still works, it is just the stock one, which reads as "the module did nothing".
        $hyvaStores = [];
        foreach ($this->resolveFrontendThemes() as $storeCode => $path) {
            if (stripos($path, 'hyva') !== false) {
                $hyvaStores[] = $storeCode;
            }
        }

        if ($hyvaStores && !$this->moduleManager->isEnabled('Hyva_LumaCheckout')) {
            $out[] = Check::fail(
                self::G_CHECKOUT,
                'Hyvä checkout compatibility',
                sprintf('Hyvä is active on store view(s) %s but Hyva_LumaCheckout is not enabled', implode(', ', $hyvaStores)),
                'FastMagento Checkout extends the Luma checkout.root block, which Hyvä does not '
                . 'render, so it stays inert. Install the free fallback: '
                . 'composer require hyva-themes/magento2-luma-checkout'
            );
        } elseif ($hyvaStores) {
            $out[] = Check::ok(self::G_CHECKOUT, 'Hyvä checkout compatibility', 'Hyva_LumaCheckout enabled');
        }

        return $out;
    }

    /**
     * @return Check[]
     */
    private function checkPhp(): array
    {
        $out = [];
        $memory = ini_get('memory_limit');
        $bytes = $this->toBytes((string) $memory);
        $out[] = ($bytes > 0 && $bytes < 2 * 1024 * 1024 * 1024)
            ? Check::warn(self::G_PHP, 'memory_limit', (string) $memory, 'Magento recommends at least 2G for CLI/indexing.')
            : Check::ok(self::G_PHP, 'memory_limit', (string) $memory);

        $maxExecution = (int) ini_get('max_execution_time');
        $out[] = ($maxExecution > 0 && $maxExecution < 600)
            ? Check::warn(self::G_PHP, 'max_execution_time', (string) $maxExecution, 'Long reindex/deploy operations may be killed; 1800 or 0 is typical.')
            : Check::ok(self::G_PHP, 'max_execution_time', $maxExecution === 0 ? 'unlimited' : (string) $maxExecution);

        return $out;
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0; // unlimited
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
