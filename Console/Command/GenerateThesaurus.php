<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\Ai\AiConfig;
use ParkkTech\FastMagento\Model\Ai\ThesaurusGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI mirror of the admin "Generate Thesaurus" button: build shopper-facing synonym groups from
 * the store's own catalogue vocabulary via the Claude API and merge them into Search > Synonyms.
 *
 *   bin/magento fastmagento:thesaurus:generate
 *
 * Exposing this on the CLI unblocks automation / deploy pipelines that the admin-only button
 * previously prevented. Review Search > Synonyms afterwards and reindex catalogsearch_fulltext.
 */
class GenerateThesaurus extends Command
{
    public function __construct(
        private readonly ThesaurusGenerator $generator,
        private readonly State $appState,
        private readonly AiConfig $aiConfig
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:thesaurus:generate')
            ->setDescription('Generate a search synonym thesaurus from the catalogue (merges into Search > Synonyms).');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable $e) {
            // Area already set (e.g. under a wrapping command) — safe to continue.
        }

        // Fail fast with a clear message rather than running the full DB vocabulary scan first and
        // only then erroring inside the API call.
        if ($this->aiConfig->getApiKey() === '') {
            $output->writeln('<error>No Claude API key is configured.</error> '
                . 'Set it under Stores > Configuration > FastMagento > AI Assistant.');
            return Command::FAILURE;
        }

        try {
            $stats = $this->generator->generateAndImport();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln('<comment>' . $e->getTraceAsString() . '</comment>');
            }
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Done.</info> Scanned %d catalogue terms. Added %d new group(s), expanded %d existing, %d total.',
            $stats['terms_scanned'],
            $stats['added'],
            $stats['merged'],
            $stats['total']
        ));

        if (!empty($stats['preview'])) {
            $output->writeln('');
            $output->writeln('Sample groups:');
            foreach (array_slice($stats['preview'], 0, 10) as $line) {
                $output->writeln('  ' . $line);
            }
        }

        $output->writeln('');
        $output->writeln('Review <comment>Stores > Configuration > FastMagento > Instant Search &amp; Relevance > '
            . 'Synonyms</comment>, then run <comment>bin/magento indexer:reindex catalogsearch_fulltext</comment> '
            . 'to make them live.');

        return Command::SUCCESS;
    }
}
