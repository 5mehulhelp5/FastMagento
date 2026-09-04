<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Setup;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use Psr\Log\LoggerInterface;

/**
 * `bin/magento module:uninstall --remove-data ParkkTech_FastMagento` cleans up everything the
 * module created outside its own code: the four OpenSearch serving indices, the four mview
 * changelog tables, the indexer and mview state rows, its cron schedule rows and its
 * configuration. Without this class Magento removes nothing of that (it only knows about
 * db_schema.xml tables, of which this module has none).
 *
 * Every step is independent and failure-tolerant: an unreachable cluster must not stop the
 * database clean-up, and vice versa.
 */
class Uninstall implements UninstallInterface
{
    private const INDEXERS = ['fastmagento_product', 'fastmagento_category', 'fastmagento_attribute_option', 'fastmagento_review'];
    private const CRON_JOB_PREFIX = 'fastmagento_';

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $this->dropIndices();

        $setup->startSetup();
        $connection = $setup->getConnection();
        try {
            foreach (self::INDEXERS as $indexerId) {
                $connection->dropTable($setup->getTable($indexerId . '_cl'));
                $connection->delete($setup->getTable('indexer_state'), ['indexer_id = ?' => $indexerId]);
                $connection->delete($setup->getTable('mview_state'), ['view_id = ?' => $indexerId]);
            }
            $connection->delete($setup->getTable('cron_schedule'), ['job_code LIKE ?' => self::CRON_JOB_PREFIX . '%']);
            // Only this module's own settings. Companion modules (personalisation, checkout) keep
            // theirs under fastmagento/<companion>/ and remove them in their own Uninstall —
            // Magento uninstalls dependents first, so theirs has already run by now.
            $where = $connection->quoteInto('path LIKE ?', 'fastmagento/%');
            foreach (['fastmagento/personalization/%', 'fastmagento/event/%', 'fastmagento/checkout/%'] as $companion) {
                $where .= ' AND ' . $connection->quoteInto('path NOT LIKE ?', $companion);
            }
            $connection->delete($setup->getTable('core_config_data'), $where);
            $connection->delete($setup->getTable('flag'), ['flag_code LIKE ?' => 'fastmagento_%']);
            // Magento reverts and forgets DATA patches on uninstall but never SCHEMA patches, so
            // without this the schedule-mode patch would be skipped on a reinstall and the
            // indexers would come back in Update-on-Save mode.
            // (backslashes doubled twice: once for PHP, once for LIKE's own escaping)
            $connection->delete($setup->getTable('patch_list'), ['patch_name LIKE ?' => 'ParkkTech\\\\FastMagento\\\\Setup\\\\Patch\\\\%']);
        } catch (\Throwable $e) {
            $this->logger->error('[FastMagento] uninstall: database clean-up failed: ' . $e->getMessage());
        }
        $setup->endSetup();
    }

    private function dropIndices(): void
    {
        try {
            $client = $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
            foreach ([
                $this->openSearchConfig->getIndexName(),
                $this->openSearchConfig->getCategoryIndexName(),
                $this->openSearchConfig->getAttributeOptionIndexName(),
                $this->openSearchConfig->getReviewIndexName(),
            ] as $index) {
                if ($index !== '' && $client->indexExists($index)) {
                    $client->deleteIndex($index);
                    $this->logger->info('[FastMagento] uninstall: deleted index ' . $index);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[FastMagento] uninstall: could not delete the OpenSearch indices: ' . $e->getMessage());
        }
    }
}
