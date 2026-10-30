<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Application;
use Druid\Major\RectorRunner;
use Druid\Major\UpgradeStatusRunner;
use Druid\Output\DruidStyle;
use Druid\Plan\MajorPlanner;
use Druid\Plan\Planner;
use Druid\Plan\PlanPrinter;
use Druid\Plan\Step;
use Druid\Scan\ScanResult;
use Druid\Scan\Scanner;
use Druid\Services;
use Druid\Site\Site;
use Druid\SiteContext;
use Druid\Upgrade\CommandBuilder;
use Druid\Upgrade\ConsoleEngineListener;
use Druid\Upgrade\Engine;
use Druid\Major\PhpstanRunner;
use Druid\Upgrade\Handler\InfoFileHandler;
use Druid\Upgrade\Handler\LenientHandler;
use Druid\Upgrade\Handler\PhpPlatformHandler;
use Druid\Upgrade\Handler\ReadinessHandler;
use Druid\Upgrade\Handler\RectorHandler;
use Druid\Upgrade\Strategy\StrategyFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base class for druid commands: site resolution, styled output and confirmations.
 */
abstract class DruidCommand extends Command
{
    /** Exit code when some steps were deferred but the run itself completed. */
    public const PARTIAL = 2;

    protected function services(): Services
    {
        $application = $this->getApplication();
        if ($application instanceof Application) {
            return $application->services();
        }
        return new Services();
    }

    protected function style(InputInterface $input, OutputInterface $output): DruidStyle
    {
        return new DruidStyle($input, $output);
    }

    /**
     * Style writing to stderr, so progress doesn't mix with JSON on stdout.
     */
    protected function errorStyle(InputInterface $input, OutputInterface $output): DruidStyle
    {
        return new DruidStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
    }

    /**
     * Resolves the target site from `@alias`/--site, --root, or the current directory.
     */
    protected function resolveSite(InputInterface $input): Site
    {
        return $this->services()->locator()->locate(
            $this->stringOption($input, 'site'),
            $this->stringOption($input, 'root'),
            getcwd() ?: '.',
        );
    }

    protected function context(InputInterface $input, ?Site $site = null): SiteContext
    {
        return $this->services()->forSite($site ?? $this->resolveSite($input), $this->stringOption($input, 'uri'));
    }

    /**
     * Sites to act on: every registered site with --all, otherwise the resolved site.
     *
     * @return list<Site>
     */
    protected function targetSites(InputInterface $input): array
    {
        if ($input->hasOption('all') && $input->getOption('all')) {
            $sites = array_values($this->services()->registry()->all());
            if ($sites === []) {
                throw new \RuntimeException('No sites registered yet. Run `druid site:discover` or `druid site:add` first.');
            }
            return $sites;
        }
        return [$this->resolveSite($input)];
    }

    /**
     * The stored scan when it is newer than composer.lock and less than a day old, otherwise a fresh scan.
     */
    protected function scanFor(SiteContext $context, DruidStyle $io, bool $rescan = false): ScanResult
    {
        $services = $this->services();
        $stored = $rescan ? null : $services->state()->lastScan($context->site);
        if ($stored !== null) {
            $scannedAt = strtotime($stored->scannedAt) ?: 0;
            $lockFile = $context->root() . '/composer.lock';
            $lockTime = is_file($lockFile) ? (int) filemtime($lockFile) : 0;
            if ($scannedAt >= $lockTime && time() - $scannedAt < 86400) {
                $io->mutedLine(sprintf('Using scan from %s (pass --rescan to refresh).', date('Y-m-d H:i', $scannedAt)), 0);
                return $stored;
            }
        }

        $io->writeln(sprintf('<label>Scanning @%s</label> <muted>(%s)</muted>', $context->site->name, $context->environment->label()));
        $scan = (new Scanner($context, $services->releaseHistory()))->scan(false, static function (string $message) use ($io): void {
            $io->mutedLine('… ' . $message, 2);
        });
        $services->state()->saveScan($context->site, $scan);
        return $scan;
    }

    protected function planner(SiteContext $context): Planner
    {
        return new Planner(
            $context->config,
            $context->project,
            new MajorPlanner($context->project, $this->services()->releaseHistory(), $context->drush?->info, $this->services()->composerMetadata()),
        );
    }

    protected function commandBuilder(SiteContext $context): CommandBuilder
    {
        return new CommandBuilder($context->project, $context->composer->supportsMinimalChanges());
    }

    protected function engine(SiteContext $context, ScanResult $scan, DruidStyle $io, bool $applyRector = false): Engine
    {
        $handlers = [
            new ReadinessHandler($context->drush !== null ? new UpgradeStatusRunner($context) : null, new PhpstanRunner($context), $scan->customModules),
            new LenientHandler($context->project, $context->composer),
            new RectorHandler(new RectorRunner($context), $applyRector),
            new InfoFileHandler($context->root()),
            new PhpPlatformHandler($context->root()),
        ];
        return new Engine(
            $context,
            $this->commandBuilder($context),
            StrategyFactory::fromConfig($context->config),
            $handlers,
            $this->services()->snapshots(),
            new ConsoleEngineListener($io),
            $scan,
            $context->config->bool('upgrade.verify', true),
        );
    }

    protected function planPrinter(SiteContext $context): PlanPrinter
    {
        return new PlanPrinter(
            $this->commandBuilder($context),
            $context->composer,
            $context->config->list('upgrade.strategies'),
            $context->config->bool('upgrade.security_bypass_audit_blocking', true),
            $context->config->list('upgrade.post_update'),
            $context->drush !== null,
        );
    }

    /**
     * Shared --security/--minor/--major/--latest/--to options for plan and upgrade commands.
     */
    protected function addScopeOptions(): void
    {
        $this
            ->addOption('security', null, InputOption::VALUE_NONE, 'Security updates only (default)')
            ->addOption('minor', null, InputOption::VALUE_NONE, 'Also apply minor and patch updates within the current major versions')
            ->addOption('major', null, InputOption::VALUE_NONE, 'Also upgrade to the next major version (implies --minor)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target version, e.g. 10.6.11, or a major such as 11')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Prefer the newest release instead of the minimum safe one')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Scan again instead of reusing a recent scan');
    }

    protected function scopeFromInput(InputInterface $input): string
    {
        $to = $this->stringOption($input, 'to');
        return match (true) {
            $this->flag($input, 'major') => Step::SCOPE_MAJOR,
            $to !== null && preg_match('/^\d+(\.x)?$/', $to) === 1 => Step::SCOPE_MAJOR,
            $this->flag($input, 'minor') => Step::SCOPE_MINOR,
            default => Step::SCOPE_SECURITY,
        };
    }

    protected function confirm(DruidStyle $io, InputInterface $input, string $question, bool $default = true): bool
    {
        if ($this->flag($input, 'yes')) {
            return true;
        }
        if (!$input->isInteractive()) {
            return $default;
        }
        return $io->confirm($question, $default);
    }

    protected function simulate(InputInterface $input): bool
    {
        return $this->flag($input, 'simulate');
    }

    protected function flag(InputInterface $input, string $name): bool
    {
        return $input->hasOption($name) && (bool) $input->getOption($name);
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        if (!$input->hasOption($name)) {
            return null;
        }
        $value = $input->getOption($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
