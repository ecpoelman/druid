<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Output\DruidStyle;
use Druid\Plan\PlanOptions;
use Druid\Plan\UpgradePlan;
use Druid\Scan\ScanResult;
use Druid\Scan\Scanner;
use Druid\Site\Site;
use Druid\SiteContext;
use Druid\Upgrade\EngineOptions;
use Druid\Upgrade\RunResult;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared flow for upgrade commands: scan, plan, show, confirm, run the engine, record history, rescan.
 */
abstract class AbstractUpgradeCommand extends DruidCommand
{
    protected function addRunOptions(): void
    {
        $this
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Skip the database dump before upgrading')
            ->addOption('no-rescan', null, InputOption::VALUE_NONE, 'Do not scan again after upgrading');
    }

    /**
     * @param callable(ScanResult): PlanOptions $options builds plan options once the scan is known
     */
    protected function upgradeSite(InputInterface $input, DruidStyle $io, Site $site, callable $options): int
    {
        $context = $this->context($input, $site);
        $scan = $this->scanFor($context, $io, $this->flag($input, 'rescan'));
        $planOptions = $options($scan);
        $plan = $this->planner($context)->build($scan, $planOptions);

        $this->planPrinter($context)->print($plan, $io);
        if ($plan->isEmpty()) {
            return self::SUCCESS;
        }

        $io->newLine();
        if ($this->simulate($input)) {
            $io->infoLine('Simulation (--simulate): nothing was changed.');
            return self::SUCCESS;
        }
        if (!$this->confirm($io, $input, sprintf('Apply %d step(s) to @%s?', count($plan->steps), $site->name), true)) {
            $io->warnLine('Cancelled - nothing was changed.');
            return self::SUCCESS;
        }

        $result = $this->engine($context, $scan, $io, $planOptions->rector)->run(
            $plan,
            EngineOptions::fromConfig($context->config, !$this->flag($input, 'no-backup'), $io->isVerboseOutput(), $planOptions->rector),
        );

        $this->recordHistory($context, $plan, $result);
        if ($result->changedCode() && !$this->flag($input, 'no-rescan') && $context->config->bool('upgrade.rescan', true)) {
            $this->rescan($context, $io);
        }
        return $result->exitCode();
    }

    private function recordHistory(SiteContext $context, UpgradePlan $plan, RunResult $result): void
    {
        $this->services()->state()->appendHistory($context->site, [
            'at' => date(DATE_ATOM),
            'command' => (string) $this->getName(),
            'scope' => $plan->scope,
            'exit' => $result->exitCode(),
            'snapshot' => $result->snapshot?->id,
            'upgraded' => array_values(array_map(
                static fn ($r): array => ['step' => $r->step->id, 'from' => $r->step->from, 'to' => $r->achieved],
                array_filter($result->steps, static fn ($r): bool => $r->changedCode()),
            )),
            'deferred' => array_values(array_map(
                static fn ($r): array => ['step' => $r->step->id, 'reason' => $r->message],
                $result->deferred(),
            )),
        ]);
    }

    private function rescan(SiteContext $context, DruidStyle $io): void
    {
        $io->newLine();
        $io->mutedLine('Rescanning to confirm the result…', 0);
        $scan = (new Scanner($context, $this->services()->releaseHistory()))->scan(false, static fn (string $message) => null);
        $this->services()->state()->saveScan($context->site, $scan);
        $summary = $scan->summary();
        $io->keyValue('Security issues', DruidStyle::count((int) $summary['security'], 'high'));
        $io->keyValue('Unsupported', DruidStyle::count((int) $summary['unsupported'], 'warn'));
        $io->keyValue('Minor updates', DruidStyle::count((int) $summary['minor'], 'low'));
        $io->keyValue('Major updates', DruidStyle::count((int) $summary['major'], 'low'));
    }

    /**
     * Runs `$callback` for every target site and returns the worst exit code.
     *
     * @param callable(Site): int $callback
     */
    protected function forEachSite(InputInterface $input, DruidStyle $io, callable $callback): int
    {
        $sites = $this->targetSites($input);
        $exit = self::SUCCESS;
        foreach ($sites as $site) {
            if (count($sites) > 1) {
                $io->section('@' . $site->name . ' - ' . $site->root);
            }
            try {
                $code = $callback($site);
            } catch (\Throwable $e) {
                $io->failLine("@{$site->name}: {$e->getMessage()}");
                $code = self::FAILURE;
            }
            $exit = max($exit, $code === self::FAILURE ? 3 : $code);
        }
        return $exit === 3 ? self::FAILURE : $exit;
    }
}
