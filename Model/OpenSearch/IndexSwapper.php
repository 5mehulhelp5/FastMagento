<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\OpenSearch;

use Psr\Log\LoggerInterface;

/**
 * Blue/green full rebuild for FastMagento's serving indices.
 *
 * The configured index name is an alias. A full rebuild fills a new versioned index
 * (<alias>_v<timestamp>) while the storefront keeps reading the current one, then moves the
 * alias in a single atomic updateAliases call and drops the previous index. Readers never see
 * an empty or half-built index, which the old delete-then-recreate rebuild caused for the whole
 * duration of a full reindex. If filling the new index fails, it is dropped and the alias is
 * left untouched.
 *
 * Installs from before 2.11 have a concrete index under the alias name; the first rebuild
 * replaces it in the same atomic call (remove_index + add alias), so there is no gap there either.
 *
 * Writes made to the alias while a rebuild runs (stock sync, instant save, warm-on-miss, review
 * summaries) land in the outgoing index and are gone after the swap, unless the rebuild itself
 * read the newer value. Mview-tracked changes are replayed after the rebuild, so this only
 * affects the direct writers, for a window as long as the rebuild (seconds on a typical catalogue).
 * The next change to the same product, or the next reindex, brings it back in line.
 */
class IndexSwapper
{
    /**
     * An unaliased versioned index older than this is debris from an interrupted rebuild.
     * Younger ones may belong to a rebuild still running in another process, so they are kept.
     */
    private const ORPHAN_AGE_SECONDS = 86400;

    /**
     * Refuse the swap when the new index holds less than this share of the current one's
     * documents: that is a rebuild that failed part-way (the indexers skip bad rows and keep
     * going), not a real catalogue change. The current index stays live and the failure is logged.
     */
    private const MIN_DOC_RATIO = 0.5;

    /**
     * Set to 1 to swap in a rebuild even when it fails the document-count guard, e.g. after a
     * genuine bulk delete: FASTMAGENTO_FORCE_SWAP=1 bin/magento indexer:reindex fastmagento_product
     */
    private const FORCE_ENV = 'FASTMAGENTO_FORCE_SWAP';

    /**
     * Whether $index is a versioned index this class created for $alias (exact suffix match, so
     * one alias's pattern can never claim another alias's indices).
     */
    public static function isVersionOf(string $alias, string $index): bool
    {
        return (bool) preg_match('/^' . preg_quote($alias, '/') . '_v\\d{14}[0-9a-f]{4}$/', $index);
    }

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param object $client Magento OpenSearch SearchClient (createIndex(), getOpenSearchClient())
     * @param string $alias the index name every reader uses
     * @param array $settings index body (settings + mappings) passed to createIndex()
     * @param callable(string): void $fill writes every document into the index name it is given
     * @throws \Throwable when the fill fails (the new index has been dropped by then)
     */
    public function rebuild($client, string $alias, array $settings, callable $fill): void
    {
        $indices = $client->getOpenSearchClient()->indices();
        $target = $alias . '_v' . gmdate('YmdHis') . bin2hex(random_bytes(2));

        $client->createIndex($target, $settings);
        try {
            $fill($target);
            $indices->refresh(['index' => $target]);
        } catch (\Throwable $e) {
            $this->drop($indices, $target);
            throw $e;
        }

        $current = $this->countDocs($indices, $alias);
        $built = $this->countDocs($indices, $target);
        if ($current > 0 && $built < $current * self::MIN_DOC_RATIO && getenv(self::FORCE_ENV) !== '1') {
            $this->drop($indices, $target);
            throw new \RuntimeException(sprintf(
                'rebuild of %s produced %d documents against %d live; keeping the live index. '
                . 'If the catalogue really shrank that much, rerun the reindex with '
                . self::FORCE_ENV . '=1.',
                $alias,
                $built,
                $current
            ));
        }

        $actions = [['add' => ['index' => $target, 'alias' => $alias]]];
        $previous = [];
        if ($indices->existsAlias(['name' => $alias])) {
            $previous = array_keys($indices->getAlias(['name' => $alias]));
            foreach ($previous as $old) {
                $actions[] = ['remove' => ['index' => $old, 'alias' => $alias]];
            }
        } elseif ($indices->exists(['index' => $alias])) {
            $actions[] = ['remove_index' => ['index' => $alias]];
        }
        try {
            $indices->updateAliases(['body' => ['actions' => $actions]]);
        } catch (\Throwable $e) {
            // e.g. two rebuilds racing: the alias is unchanged, so don't leave the new index behind.
            $this->drop($indices, $target);
            throw $e;
        }

        foreach ($previous as $old) {
            if ($old !== $target) {
                $this->drop($indices, $old);
            }
        }
        $this->dropOrphans($indices, $alias, $target);
    }

    /**
     * Drop versioned indices that never got the alias (an interrupted rebuild) once they are
     * old enough that no running rebuild can still own them.
     */
    private function dropOrphans($indices, string $alias, string $keep): void
    {
        try {
            $found = $indices->get(['index' => $alias . '_v*']);
        } catch (\Throwable $e) {
            return;
        }
        $cutoffMs = (time() - self::ORPHAN_AGE_SECONDS) * 1000;
        foreach ($found as $name => $info) {
            $created = (int) ($info['settings']['index']['creation_date'] ?? PHP_INT_MAX);
            if ($name !== $keep
                && self::isVersionOf($alias, (string) $name)
                && empty($info['aliases'])
                && $created < $cutoffMs
            ) {
                $this->drop($indices, (string) $name);
            }
        }
    }

    private function countDocs($indices, string $index): int
    {
        try {
            if (!$indices->exists(['index' => $index])) {
                return 0;
            }
            $stats = $indices->stats(['index' => $index, 'metric' => 'docs']);
            return (int) ($stats['_all']['primaries']['docs']['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function drop($indices, string $index): void
    {
        try {
            $indices->delete(['index' => $index]);
        } catch (\Throwable $e) {
            $this->logger->warning('[FastMagento] could not drop index ' . $index . ': ' . $e->getMessage());
        }
    }
}
