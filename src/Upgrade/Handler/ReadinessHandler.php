<?php

declare(strict_types=1);

namespace Druid\Upgrade\Handler;

use Druid\Major\PhpstanRunner;
use Druid\Major\UpgradeStatusRunner;
use Druid\Plan\Step;
use Druid\Upgrade\EngineListener;
use Druid\Upgrade\StepHandler;
use Druid\Upgrade\StepResult;

/**
 * Runs upgrade_status before a major upgrade. Informational: findings never stop the run.
 */
final class ReadinessHandler implements StepHandler
{
    private const SHOW_EXTENSIONS = 10;

    /**
     * @param array<string, string> $customExtensions name => directory, analysed with phpstan when upgrade_status is missing
     */
    public function __construct(
        private readonly ?UpgradeStatusRunner $upgradeStatus,
        private readonly ?PhpstanRunner $phpstan = null,
        private readonly array $customExtensions = [],
    ) {
    }

    public function supports(Step $step): bool
    {
        return $step->kind === Step::READINESS;
    }

    public function handle(Step $step, EngineListener $listener): StepResult
    {
        if ($this->upgradeStatus === null || !$this->upgradeStatus->isInstalled()) {
            if ($this->phpstan !== null && $this->customExtensions !== [] && $this->phpstan->isAvailable()) {
                return $this->phpstanReadiness($step, $listener);
            }
            return new StepResult($step, StepResult::SKIPPED, message: $this->upgradeStatus === null
                ? 'Drush is not available - readiness check skipped.'
                : 'upgrade_status is not installed - run `druid upgrade:check --install-upgrade-status` for a deprecation report.');
        }

        // Contrib is replaced by its new-major releases during the run, so only custom code is worth scanning.
        if ($this->customExtensions === []) {
            return new StepResult($step, StepResult::DONE, message: 'No custom modules or themes to check (`druid upgrade:check` reports on contrib).');
        }
        $names = array_keys($this->customExtensions);
        $listener->notice('info', 'Checking custom code with upgrade_status: ' . implode(', ', $names));
        $report = $this->upgradeStatus->analyze($names);
        if ($report === null) {
            return new StepResult($step, StepResult::DONE, message: 'Readiness check could not run', warnings: ['upgrade_status analysis failed - check `drush upgrade_status:analyze ' . implode(' ', $names) . '` manually.']);
        }
        if (!$report->structured) {
            return new StepResult($step, StepResult::DONE, message: 'upgrade_status analysis finished (see `drush upgrade_status:analyze ' . implode(' ', $names) . '` for details)');
        }
        if ($report->count() === 0) {
            return new StepResult($step, StepResult::DONE, message: 'upgrade_status found no deprecations');
        }

        foreach (array_slice($report->byExtension(), 0, self::SHOW_EXTENSIONS, true) as $extension => $count) {
            $listener->notice('warn', "{$extension}: {$count} deprecation(s)");
        }
        return new StepResult($step, StepResult::DONE, message: sprintf('upgrade_status: %d issue(s) in %d extension(s) - updated contrib releases and rector fix most of them', $report->count(), count($report->byExtension())));
    }

    private function phpstanReadiness(Step $step, EngineListener $listener): StepResult
    {
        $listener->notice('info', 'upgrade_status is not installed - checking custom code with phpstan-drupal deprecation rules');
        $report = $this->phpstan?->analyze($this->customExtensions);
        if ($report === null) {
            return new StepResult($step, StepResult::DONE, message: 'Readiness check could not run', warnings: ['phpstan analysis failed - run `druid upgrade:check --install-upgrade-status` for a full report.']);
        }
        if ($report->count() === 0) {
            return new StepResult($step, StepResult::DONE, message: 'phpstan found no deprecated or removed API use in custom code');
        }
        $errors = array_values(array_filter($report->issues, static fn (array $issue): bool => $issue['severity'] === 'error'));
        foreach (array_slice($report->byExtension(), 0, self::SHOW_EXTENSIONS, true) as $extension => $count) {
            $listener->notice('warn', "{$extension}: {$count} deprecated or removed API use(s)");
        }
        foreach (array_slice($errors, 0, self::SHOW_EXTENSIONS) as $issue) {
            $listener->notice('warn', basename($issue['file']) . ":{$issue['line']} {$issue['message']}");
        }
        return new StepResult($step, StepResult::DONE, message: sprintf('phpstan: %d issue(s) in custom code, %d will fail on the new major - rector (--rector) fixes many; fix the rest by hand', $report->count(), count($errors)));
    }
}
