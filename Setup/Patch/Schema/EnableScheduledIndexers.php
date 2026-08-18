<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use ParkkTech\FastMagento\Setup\Patch\Data\InitializeIndexers;
use Psr\Log\LoggerInterface;

/**
 * Put the three FastMagento indexers into "Update by Schedule" on install.
 *
 * WHY A SCHEMA PATCH AND NOT A DATA PATCH
 * ---------------------------------------
 * Switching an indexer to schedule mode creates its mview changelog table — a DDL statement.
 * Magento wraps DATA patches in a transaction, and MySQL does not allow DDL inside one, so doing
 * this from a data patch fails with "DDL statements are not allowed in transactions" and the mode
 * silently stays on Update on Save. Schema patches are applied outside that transaction, which is
 * where table-creating work belongs.
 *
 * Schedule mode is what the module's etc/mview.xml subscriptions are written for. On Update on
 * Save, every admin product save reprojects synchronously instead of going through the changelog.
 */
class EnableScheduledIndexers implements SchemaPatchInterface
{
    public function __construct(
        private readonly IndexerInterfaceFactory $indexerFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(): self
    {
        foreach (InitializeIndexers::INDEXERS as $indexerId) {
            try {
                $indexer = $this->indexerFactory->create()->load($indexerId);
                if (!$indexer->isScheduled()) {
                    $indexer->setScheduled(true);
                }
            } catch (\Throwable $e) {
                // Never break setup:upgrade over an indexer mode. fastmagento:doctor reports it.
                $this->logger->warning(sprintf(
                    '[FastMagento] Could not set schedule mode on "%s": %s. '
                    . 'Run: bin/magento indexer:set-mode schedule %s',
                    $indexerId,
                    $e->getMessage(),
                    $indexerId
                ));
            }
        }

        return $this;
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
