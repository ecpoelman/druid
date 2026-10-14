<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Drupal\PhpRequirements;
use Druid\Major\UpgradeStatusReport;
use Druid\Major\PhpstanRunner;
use Druid\Major\UpgradeStatusRunner;
use Druid\Output\DruidStyle;
use Druid\Output\Symbols;
use Druid\Plan\MajorPlanner;
use Druid\Security\VersionResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Major-upgrade readiness: module compatibility, PHP, Drush, custom code and upgrade_status findings.
 */
#[AsCommand(name: 'upgrade:check', description: 'Check readiness for the next Drupal major (modules, PHP, Drush, upgrade_status)', aliases: ['up:check', 'readiness'])]
final class UpgradeCheckCommand extends DruidCommand
{
    private const ACTION_LABELS = [
        'none' => '<ok>ready</ok>',
        'bridge' => '<low>update first</low>',
        'companion' => '<moderate>moves with core</moderate>',
        'lenient' => '<high>no support yet (lenient)</high>',
        'unsupported' => '<critical> unsupported </critical>',
        'unknown' => '<muted>unknown</muted>',
    ];

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target major or version, e.g. 11 or 11.2 (default: next major)')
            ->addOption('install-upgrade-status', null, InputOption::VALUE_NONE, 'Install and enable drupal/upgrade_status (dev dependency) to scan for deprecated code')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table or json', 'table')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Scan again instead of reusing a recent scan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->stringOption($input, 'format') === 'json';
        $io = $this->style($input, $output);
        $progress = $json ? $this->errorStyle($input, $output) : $io;
        $context = $this->context($input);
        $scan = $this->scanFor($context, $progress, $this->flag($input, 'rescan'));

        $majorPlanner = new MajorPlanner($context->project, $this->services()->releaseHistory(), $context->drush?->info, $this->services()->composerMetadata());
        $notes = [];
        $target = $majorPlanner->target($scan, $this->stringOption($input, 'to'), $notes);
        if ($target === null) {
            foreach ($notes as $note) {
                $progress->warnLine($note);
            }
            return self::FAILURE;
        }
        $targetMajor = VersionResolver::major($target);
        $bridgeCore = $scan->core->latestMinor !== null && VersionResolver::greaterThan($scan->core->latestMinor, $scan->core->current)
            ? $scan->core->latestMinor
            : $scan->core->current;

        $progress->mutedLine("Checking Drupal {$target} support of every contrib project…", 0);
        $rows = $majorPlanner->compatibility($bridgeCore, $target);

        $upgradeStatus = $context->drush !== null ? new UpgradeStatusRunner($context) : null;
        if ($upgradeStatus !== null && $this->flag($input, 'install-upgrade-status') && !$this->simulate($input)) {
            $progress->mutedLine('Installing drupal/upgrade_status…', 0);
            $install = $upgradeStatus->install();
            if (!$install->successful()) {
                $progress->warnLine('Could not install upgrade_status: ' . implode(' ', $install->tail(3)));
            }
        }
        $report = null;
        if ($upgradeStatus !== null && $upgradeStatus->isInstalled()) {
            $progress->mutedLine('Running upgrade_status (this can take a few minutes)…', 0);
            $report = $upgradeStatus->analyze();
        }
        $source = 'upgrade_status';
        $phpstan = new PhpstanRunner($context);
        if ($report === null && !($upgradeStatus?->isInstalled() ?? false) && $scan->customModules !== [] && $phpstan->isAvailable()) {
            $progress->mutedLine('Checking custom code with phpstan-drupal deprecation rules…', 0);
            $report = $phpstan->analyze($scan->customModules);
            $source = 'phpstan';
        }

        $php = isset($scan->environment['php']) ? (string) $scan->environment['php'] : null;
        $phpProblems = PhpRequirements::problems($targetMajor, $php, $context->project);
        $drushVersion = $context->drush?->info->version;
        $drushOk = $context->drush === null || $targetMajor < 11 || $context->drush->info->majorVersion() >= 13;

        if ($json) {
            $output->writeln((string) json_encode([
                'site' => $context->site->name,
                'current' => $scan->core->current,
                'target' => $target,
                'php' => ['version' => $php, 'minimum' => PhpRequirements::minimumFor($targetMajor), 'problems' => $phpProblems],
                'drush' => ['version' => $drushVersion, 'compatible' => $drushOk],
                'modules' => $rows,
                'custom_modules' => array_keys($scan->customModules),
                'upgrade_status' => $report?->toArray(),
                'deprecations_source' => $report !== null ? $source : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $io->heading(sprintf('Drupal %s readiness for @%s (now %s)', $target, $context->site->name, $scan->core->current));
        $io->keyValue('PHP', $php === null ? '<warn>unknown</warn>' : ($phpProblems === [] ? "<ok>{$php}</ok>" : "<fail>{$php}</fail>") . ' <muted>(needs ' . PhpRequirements::minimumFor($targetMajor) . '+)</muted>');
        $io->keyValue('Drush', $drushVersion === null ? '<warn>not installed</warn>' : ($drushOk ? "<ok>{$drushVersion}</ok>" : "<warn>{$drushVersion}</warn> <muted>(moves to ^13 with core)</muted>"));
        $io->keyValue('Core path', "{$scan->core->current} " . Symbols::ARROW . ($bridgeCore !== $scan->core->current ? " {$bridgeCore} " . Symbols::ARROW : '') . " {$target}");
        foreach ($phpProblems as $problem) {
            $io->failLine($problem);
        }

        $this->renderModules($io, $output, $rows);
        $this->renderUpgradeStatus($io, $report, $source === 'phpstan' || ($upgradeStatus !== null && $upgradeStatus->isInstalled()), $source);

        if ($scan->customModules !== []) {
            $io->newLine();
            $io->infoLine('Custom code: ' . implode(', ', array_keys($scan->customModules)) . ' - `druid upgrade:major --rector` applies drupal-rector.');
        }

        $blocking = count(array_filter($rows, static fn (array $r): bool => in_array($r['action'], ['lenient', 'unsupported'], true)));
        $io->newLine();
        if ($blocking === 0 && $phpProblems === []) {
            $io->okLine("Ready: run `druid upgrade:major {$targetMajor}` (or `druid plan --major` to review the steps).");
        } else {
            $io->warnLine(sprintf('%d module(s) need attention. druid will use the lenient plugin for them; review with `druid plan --major`.', $blocking));
        }
        return self::SUCCESS;
    }

    /**
     * @param array<string, array{current: string, action: string, version: ?string}> $rows
     */
    private function renderModules(DruidStyle $io, OutputInterface $output, array $rows): void
    {
        $io->newLine();
        if ($rows === []) {
            $io->mutedLine('No contrib modules required directly in composer.json.', 0);
            return;
        }
        $table = new Table($output);
        $table->setHeaders(['Project', 'Installed', 'Status', 'Use']);
        foreach ($rows as $package => $row) {
            $table->addRow([
                $package,
                $row['current'],
                self::ACTION_LABELS[$row['action']] ?? $row['action'],
                $row['version'] ?? '',
            ]);
        }
        $table->render();
    }

    private function renderUpgradeStatus(DruidStyle $io, ?UpgradeStatusReport $report, bool $installed, string $source = 'upgrade_status'): void
    {
        $io->newLine();
        if (!$installed) {
            $io->infoLine('Deprecated code is not checked - add `--install-upgrade-status` to install drupal/upgrade_status and scan it.');
            return;
        }
        if ($report === null) {
            $io->warnLine($source === 'phpstan' ? 'phpstan could not analyse the custom code.' : 'upgrade_status could not run (does Drush bootstrap Drupal?).');
            return;
        }
        if (!$report->structured) {
            $io->infoLine('upgrade_status output:');
            foreach (array_slice(preg_split('/\R/', trim($report->raw)) ?: [], 0, 40) as $line) {
                $io->mutedLine($line, 4);
            }
            return;
        }
        if ($report->count() === 0) {
            $io->okLine("{$source} found no deprecated code.");
            return;
        }
        $io->warnLine(sprintf('%s: %d issue(s)', $source, $report->count()));
        foreach (array_slice($report->byExtension(), 0, 15, true) as $extension => $count) {
            $io->mutedLine(sprintf('%s %-30s %d', Symbols::BULLET, $extension, $count), 4);
        }
        if ($io->isVerboseOutput()) {
            foreach (array_slice($report->issues, 0, 50) as $issue) {
                $io->mutedLine(sprintf('%s:%d %s', $issue['file'], $issue['line'], $issue['message']), 6);
            }
        }
    }
}
