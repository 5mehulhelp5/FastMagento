<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Config\Source;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The attributes that can actually work as instant-search facets, used for three things:
 *
 *  1. the admin multiselect for `fastmagento/search/facet_attributes` (so a facet can no longer be
 *     configured by typing a code that does not exist, or one Magento will not aggregate);
 *  2. the auto-detected default when that setting is left blank;
 *  3. the `fastmagento:doctor` facet check.
 *
 * ELIGIBILITY. Facet aggregations run against Magento's NATIVE fulltext index, and Magento only
 * puts an attribute in that index when it is flagged for *search-results* layered navigation
 * (`is_filterable_in_search`). That is a different flag from the category-page one
 * (`is_filterable`) that people usually set, which is why a perfectly sensible-looking facet
 * config could silently produce nothing at all.
 */
class FacetAttributes implements OptionSourceInterface
{
    private const CACHE_KEY = 'fastmagento_facet_attribute_candidates';

    /** Cheap to rebuild; short TTL keeps admin changes visible without an explicit flush. */
    private const CACHE_TTL = 3600;

    /** @var array<int, array{code: string, label: string, input: string}>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    /**
     * Attribute codes safe to use as facets, in a stable order.
     *
     * @return string[]
     */
    public function getAutoDetectedCodes(): array
    {
        return array_column($this->getCandidates(), 'code');
    }

    /**
     * Admin multiselect options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->getCandidates() as $c) {
            $options[] = [
                'value' => $c['code'],
                'label' => sprintf('%s (%s)', $c['label'] !== '' ? $c['label'] : $c['code'], $c['code']),
            ];
        }
        return $options;
    }

    /**
     * Which of the given codes are NOT usable as facets, and why. Drives both the config-save
     * validation and the doctor report.
     *
     * @param string[] $codes
     * @return array<string, string> code => human-readable reason
     */
    public function findUnusable(array $codes): array
    {
        $eligible = array_flip($this->getAutoDetectedCodes());
        $known = $this->getAllAttributeFlags();
        $problems = [];

        foreach ($codes as $code) {
            $code = trim($code);
            if ($code === '' || isset($eligible[$code])) {
                continue;
            }
            if (!isset($known[$code])) {
                $problems[$code] = 'no such product attribute';
                continue;
            }
            $flags = $known[$code];
            if (!in_array($flags['input'], ['select', 'multiselect'], true)) {
                $problems[$code] = sprintf(
                    'input type is "%s" — only select/multiselect attributes have facetable options',
                    $flags['input']
                );
                continue;
            }
            // The common case, and the one that produced an empty facet with no explanation.
            $problems[$code] = 'not flagged "Use in Search Results Layered Navigation" '
                . '(catalog_eav_attribute.is_filterable_in_search) — set it, then reindex '
                . 'catalogsearch_fulltext';
        }

        return $problems;
    }

    /**
     * @return array<int, array{code: string, label: string, input: string}>
     */
    private function getCandidates(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            try {
                $this->memo = (array) $this->json->unserialize($cached);
                return $this->memo;
            } catch (\Throwable $e) {
                // fall through and rebuild
            }
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], [])
            ->join(
                ['c' => $this->resource->getTableName('catalog_eav_attribute')],
                'c.attribute_id = a.attribute_id',
                []
            )
            ->join(
                ['t' => $this->resource->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->columns([
                'code' => 'a.attribute_code',
                'label' => 'a.frontend_label',
                'input' => 'a.frontend_input',
            ])
            ->where('t.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY)
            ->where('a.frontend_input IN (?)', ['select', 'multiselect'])
            ->where('c.is_filterable_in_search = ?', 1)
            ->order('a.frontend_label')
            ->order('a.attribute_code');

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'code' => (string) $row['code'],
                'label' => (string) ($row['label'] ?? ''),
                'input' => (string) $row['input'],
            ];
        }

        $this->cache->save(
            $this->json->serialize($rows),
            self::CACHE_KEY,
            [\Magento\Eav\Model\Cache\Type::CACHE_TAG, \Magento\Framework\App\Config::CACHE_TAG],
            self::CACHE_TTL
        );

        return $this->memo = $rows;
    }

    /**
     * Every select/multiselect product attribute with its flags, so findUnusable() can explain
     * exactly WHY a configured code is not eligible rather than just rejecting it.
     *
     * @return array<string, array{input: string, filterable: int, filterable_in_search: int}>
     */
    private function getAllAttributeFlags(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], [])
            ->join(
                ['c' => $this->resource->getTableName('catalog_eav_attribute')],
                'c.attribute_id = a.attribute_id',
                []
            )
            ->join(
                ['t' => $this->resource->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->columns([
                'code' => 'a.attribute_code',
                'input' => 'a.frontend_input',
                'filterable' => 'c.is_filterable',
                'filterable_in_search' => 'c.is_filterable_in_search',
            ])
            ->where('t.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(string) $row['code']] = [
                'input' => (string) $row['input'],
                'filterable' => (int) $row['filterable'],
                'filterable_in_search' => (int) $row['filterable_in_search'],
            ];
        }
        return $out;
    }
}
