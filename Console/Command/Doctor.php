<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagento\Model\Doctor\Check;
use ParkkTech\FastMagento\Model\Doctor\Diagnostics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento fastmagento:doctor` — one command that answers "why isn't it working?".
 *
 * Written for stores that are ALREADY installed and hitting a problem. Every FastMagento failure
 * mode found in the field is silent: the storefront keeps returning HTTP 200 while a feature is
 * quietly off, so there is nothing in the logs a store owner would think to look at. This checks
 * each of those conditions explicitly and prints the exact command or setting that fixes it.
 *
 * Exit codes are CI-friendly: 0 = clean, 1 = at least one failure.
 */
class Doctor extends Command
{
    private const OPT_JSON = 'json';
    private const OPT_STRICT = 'strict';

    public function __construct(
        private readonly Diagnostics $diagnostics,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:doctor')
            ->setDescription('Diagnose why FastMagento is not serving (indices, indexers, cron, facets, theme, checkout)')
            ->addOption(self::OPT_JSON, null, InputOption::VALUE_NONE, 'Output machine-readable JSON')
            ->addOption(self::OPT_STRICT, null, InputOption::VALUE_NONE, 'Exit non-zero on warnings as well as failures');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Several checks resolve the active frontend theme and store-scoped config, which need an
        // area. setAreaCode throws if one is already set (e.g. under a test harness) — harmless.
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Throwable $e) {
            // area already set
        }

        $checks = $this->diagnostics->run();

        $failures = 0;
        $warnings = 0;
        foreach ($checks as $check) {
            if ($check->status === Check::FAIL) {
                $failures++;
            } elseif ($check->status === Check::WARN) {
                $warnings++;
            }
        }

        if ($input->getOption(self::OPT_JSON)) {
            $output->writeln((string) json_encode([
                'failures' => $failures,
                'warnings' => $warnings,
                'checks' => array_map(static fn (Check $c) => [
                    'status' => $c->status,
                    'group' => $c->group,
                    'title' => $c->title,
                    'detail' => $c->detail,
                    'fix' => $c->fix,
                ], $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($failures, $warnings, (bool) $input->getOption(self::OPT_STRICT));
        }

        $this->renderTable($output, $checks);

        $output->writeln('');
        if ($failures === 0 && $warnings === 0) {
            $output->writeln('<info>All checks passed — FastMagento is configured to serve.</info>');
        } else {
            $output->writeln(sprintf(
                '<comment>%d failure(s), %d warning(s).</comment> Each line above marked ✗ or ! includes the fix.',
                $failures,
                $warnings
            ));
        }

        return $this->exitCode($failures, $warnings, (bool) $input->getOption(self::OPT_STRICT));
    }

    /**
     * @param Check[] $checks
     */
    private function renderTable(OutputInterface $output, array $checks): void
    {
        $output->writeln('');
        $output->writeln('<info>FastMagento Doctor</info>');

        $currentGroup = null;
        foreach ($checks as $check) {
            if ($check->group !== $currentGroup) {
                $currentGroup = $check->group;
                $output->writeln('');
                $output->writeln(sprintf('<comment>%s</comment>', strtoupper($currentGroup)));
            }

            [$icon, $style] = match ($check->status) {
                Check::OK => ['✓', 'info'],
                Check::WARN => ['!', 'comment'],
                Check::FAIL => ['✗', 'error'],
                default => ['-', 'comment'],
            };

            $output->writeln(sprintf(
                '  <%s>%s</%s> %-34s %s',
                $style,
                $icon,
                $style,
                $check->title,
                $check->detail
            ));

            if ($check->fix !== '') {
                // Wrap the remediation so a long fix stays readable in an 80-column terminal.
                foreach (explode("\n", wordwrap($check->fix, 88)) as $line) {
                    $output->writeln(sprintf('      <comment>→ %s</comment>', $line));
                }
            }
        }
    }

    private function exitCode(int $failures, int $warnings, bool $strict): int
    {
        if ($failures > 0) {
            return Command::FAILURE;
        }
        if ($strict && $warnings > 0) {
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
