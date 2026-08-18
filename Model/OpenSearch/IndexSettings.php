<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\OpenSearch;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Index-creation settings shared by every FastMagento OpenSearch index.
 *
 * WHY THIS EXISTS
 * ---------------
 * FastMagento used to let OpenSearch create its indices implicitly, which meant they inherited
 * the cluster default `index.mapping.total_fields.limit` of 1000. The product index maps every
 * indexable attribute under a `dynamic: true` `attributes` object, so an attribute-heavy catalog
 * blows straight past that ceiling. When it does, OpenSearch rejects the offending bulk items
 * with `Limit of total fields [1000] has been exceeded` — per item, not for the whole request —
 * so the indexer previously logged the rejection and reported success while silently dropping
 * documents.
 *
 * Setting the limit at create time (rather than via a cluster-side index template an admin has to
 * remember) means it survives every `executeFull()`, which deletes and recreates the index.
 */
class IndexSettings
{
    /** Admin-configurable ceiling on mapped fields per FastMagento index. */
    public const XML_PATH_TOTAL_FIELDS_LIMIT = 'fastmagento/indexing/os_total_fields_limit';

    /**
     * Matches etc/config.xml. Chosen to clear a large attribute set with headroom while staying
     * well inside what OpenSearch will happily hold in the cluster state.
     */
    public const DEFAULT_TOTAL_FIELDS_LIMIT = 5000;

    /** Below this a normal Magento catalog cannot map, so treat it as a misconfiguration. */
    private const MIN_TOTAL_FIELDS_LIMIT = 1000;

    /** Guard rail: a mapping this wide is a modelling problem, not a limit problem. */
    private const MAX_TOTAL_FIELDS_LIMIT = 100000;

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * Configured field ceiling, clamped to a sane range.
     */
    public function getTotalFieldsLimit(?int $storeId = null): int
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PATH_TOTAL_FIELDS_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $limit = (int) $raw;
        if ($limit <= 0) {
            $limit = self::DEFAULT_TOTAL_FIELDS_LIMIT;
        }

        return max(self::MIN_TOTAL_FIELDS_LIMIT, min(self::MAX_TOTAL_FIELDS_LIMIT, $limit));
    }

    /**
     * Merge the FastMagento index settings into an index-creation body, preserving whatever the
     * caller already set (analysis, shard counts, …).
     *
     * @param array<string, mixed> $body A `['settings' => …, 'mappings' => …]` create-index body.
     * @return array<string, mixed>
     */
    public function applyTo(array $body, ?int $storeId = null): array
    {
        $settings = $body['settings'] ?? [];

        // Dotted form is used deliberately: OpenSearch accepts it alongside the nested
        // `mapping.total_fields.limit` form, and it cannot collide with a nested `mapping` key
        // a caller may already have set.
        $settings['index.mapping.total_fields.limit'] = $this->getTotalFieldsLimit($storeId);

        $body['settings'] = $settings;

        return $body;
    }
}
