<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Console\Command;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\App\Cache\TypeListInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento fastmagento:synonyms:import <pack>` — load a starter synonym pack.
 *
 * The module used to SHIP a powersports/off-road synonym list as the etc/config.xml default,
 * which meant every store that installed it inherited another store's vocabulary — a clothing
 * catalogue silently expanding "rzr", "can-am" and "skid plate". The default is now empty and the
 * curated lists live in etc/synonym-packs/ as opt-in packs.
 */
class ImportSynonyms extends Command
{
    private const ARG_PACK = 'pack';
    private const OPT_APPEND = 'append';
    private const OPT_LIST = 'list';

    private const CONFIG_PATH = 'fastmagento/search/synonyms';

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly TypeListInterface $cacheTypeList,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:synonyms:import')
            ->setDescription('Import a starter synonym pack into FastMagento search synonyms')
            ->addArgument(self::ARG_PACK, InputArgument::OPTIONAL, 'Pack name (see --list)')
            ->addOption(self::OPT_LIST, null, InputOption::VALUE_NONE, 'List available packs')
            ->addOption(self::OPT_APPEND, null, InputOption::VALUE_NONE, 'Append to existing synonyms instead of replacing');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->packDirectory();

        if ($input->getOption(self::OPT_LIST) || !$input->getArgument(self::ARG_PACK)) {
            $packs = $this->availablePacks($dir);
            if (!$packs) {
                $output->writeln('<comment>No synonym packs found.</comment>');
                return Command::SUCCESS;
            }
            $output->writeln('<info>Available synonym packs:</info>');
            foreach ($packs as $pack) {
                $output->writeln(sprintf('  %s', $pack));
            }
            $output->writeln('');
            $output->writeln('Import with: <info>bin/magento fastmagento:synonyms:import <pack></info>');
            return Command::SUCCESS;
        }

        $pack = (string) $input->getArgument(self::ARG_PACK);
        // Basename guard: the pack name reaches the filesystem, so never let it traverse.
        $file = $dir . '/' . basename($pack) . '.txt';

        if (!is_file($file) || !is_readable($file)) {
            $output->writeln(sprintf('<error>Unknown pack "%s".</error> Run with --list to see the options.', $pack));
            return Command::FAILURE;
        }

        $groups = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $groups[] = $line;
        }

        if (!$groups) {
            $output->writeln(sprintf('<error>Pack "%s" contains no synonym groups.</error>', $pack));
            return Command::FAILURE;
        }

        $value = implode("\n", $groups);

        if ($input->getOption(self::OPT_APPEND)) {
            $existing = trim((string) $this->scopeConfig->getValue(self::CONFIG_PATH));
            if ($existing !== '') {
                $value = $existing . "\n" . $value;
            }
        }

        $this->configWriter->save(self::CONFIG_PATH, $value);
        $this->reinitableConfig->reinit();
        $this->cacheTypeList->cleanType('config');

        $output->writeln(sprintf(
            '<info>Imported %d synonym group(s) from "%s"%s.</info>',
            count($groups),
            $pack,
            $input->getOption(self::OPT_APPEND) ? ' (appended)' : ' (replaced existing)'
        ));
        $output->writeln('Review them under Stores > Configuration > FastMagento > Instant Search & Relevance.');

        return Command::SUCCESS;
    }

    private function packDirectory(): string
    {
        $moduleDir = $this->componentRegistrar->getPath(
            ComponentRegistrar::MODULE,
            'ParkkTech_FastMagento'
        );

        return rtrim((string) $moduleDir, '/') . '/etc/synonym-packs';
    }

    /**
     * @return string[]
     */
    private function availablePacks(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $packs = [];
        foreach (glob($dir . '/*.txt') ?: [] as $path) {
            $packs[] = basename($path, '.txt');
        }
        sort($packs);
        return $packs;
    }
}
