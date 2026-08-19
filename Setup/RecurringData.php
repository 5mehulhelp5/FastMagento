<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use ParkkTech\FastMagento\Model\Setup\FacetAutoConfigurator;
use Psr\Log\LoggerInterface;

/**
 * Runs on EVERY `setup:upgrade` (Magento's recurring-data hook), not once — deliberately.
 *
 * The facet self-heal has to be recurring because the state it heals often only comes into
 * existence AFTER the module's one-shot data patches have run: sample data or a catalogue import
 * installed in a later deploy creates the attributes whose flags need mirroring. The configurator
 * carries its own idempotence guards (explicit facet config wins; any attribute already flagged
 * for search layered nav wins), so on a healthy store this is a couple of SELECTs and a no-op.
 *
 * Never fails setup: a store with an unreachable indexer or exotic EAV setup gets a log line, not
 * a broken deploy.
 */
class RecurringData implements InstallDataInterface
{
    public function __construct(
        private readonly FacetAutoConfigurator $facetAutoConfigurator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        try {
            $this->facetAutoConfigurator->mirrorIfUnconfigured();
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[FastMagento] Facet auto-configuration skipped during setup: ' . $e->getMessage()
            );
        }
    }
}
