<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Setup\Patch\Data;

use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Psr\Log\LoggerInterface;

/**
 * Make a fresh install build its own indexes.
 *
 * FastMagento registers THREE indexers and the storefront needs all three — the product and
 * category serving indexes, and the attribute-option dictionary that facet labels are resolved
 * from. Missing the dictionary in particular is invisible: search works, but every attribute
 * facet silently disappears.
 *
 * Rather than documenting a reindex step and hoping, this marks all three INVALID on install.
 * `setup:upgrade` then leaves them invalid, the admin raises its standard "One or more indexers
 * are invalid" notice, and the next cron run (or an explicit `indexer:reindex`) builds them.
 *
 * Deliberately does NOT reindex inline: a full build on a large catalogue would time out the
 * deploy, which is exactly the kind of failure that makes people skip the step.
 *
 * It also puts the three indexers into "Update by Schedule", which is the mode the module's
 * etc/mview.xml subscriptions are written for. On "Update on Save" every admin product save
 * fans out into a synchronous reprojection.
 */
class InitializeIndexers implements DataPatchInterface, PatchRevertableInterface
{
    /** Keep in sync with etc/indexer.xml. */
    public const INDEXERS = [
        'fastmagento_product',
        'fastmagento_category',
        'fastmagento_attribute_option',
        'fastmagento_review',
    ];

    public function __construct(
        private readonly IndexerInterfaceFactory $indexerFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(): self
    {
        foreach (self::INDEXERS as $indexerId) {
            try {
                // invalidate() is a plain UPDATE on indexer_state, so it is safe inside the
                // transaction Magento wraps data patches in. Switching to schedule mode is NOT —
                // it creates the mview changelog table, and DDL in a transaction fails. That half
                // lives in Setup\Patch\Schema\EnableScheduledIndexers.
                $this->indexerFactory->create()->load($indexerId)->invalidate();
            } catch (\Throwable $e) {
                // A missing indexer must never break setup:upgrade — the module still installs,
                // and doctor/the admin notice will report the gap.
                $this->logger->warning(sprintf(
                    '[FastMagento] Could not initialize indexer "%s": %s',
                    $indexerId,
                    $e->getMessage()
                ));
            }
        }

        return $this;
    }

    public function revert(): void
    {
        // Nothing to undo: leaving an index invalid is the safe state, and the mode is an
        // operator preference we should not silently reverse.
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
