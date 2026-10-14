<?php

declare(strict_types=1);

namespace Druid\Upgrade\Handler;

use Druid\Composer\ComposerProject;
use Druid\Composer\ComposerRunner;
use Druid\Plan\Step;
use Druid\Upgrade\EngineListener;
use Druid\Upgrade\FailureAnalyzer;
use Druid\Upgrade\FileSnapshot;
use Druid\Upgrade\LenientConfigurator;
use Druid\Upgrade\StepHandler;
use Druid\Upgrade\StepResult;

/**
 * Installs mglaman/composer-drupal-lenient and adds the planned packages to its allow-list.
 */
final class LenientHandler implements StepHandler
{
    public function __construct(
        private readonly ComposerProject $project,
        private readonly ComposerRunner $composer,
    ) {
    }

    public function supports(Step $step): bool
    {
        return $step->kind === Step::LENIENT;
    }

    public function handle(Step $step, EngineListener $listener): StepResult
    {
        if ($step->packages === []) {
            return new StepResult($step, StepResult::SKIPPED, message: 'No packages to allow.');
        }

        $snapshot = FileSnapshot::capture($this->project->root);
        $attempts = LenientConfigurator::setupAttempts($this->project, $step->packages);
        foreach ($attempts as $number => $attempt) {
            $listener->attemptStarted($number + 1, $attempt, $this->composer->commandLine($attempt->args, $attempt->env)->display());
            $result = $this->composer->run($attempt->args, $attempt->env);
            if ($result->successful()) {
                continue;
            }
            $diagnosis = (new FailureAnalyzer($this->project))->analyze($result->combined(), $step);
            $listener->attemptFailed($diagnosis, $result->combined());
            $vendorTouched = $snapshot->lockChanged() || str_contains($result->combined(), 'Package operations:');
            $snapshot->restore();
            if ($vendorTouched) {
                $this->composer->install();
            }
            return new StepResult($step, StepResult::DEFERRED, attempts: $number + 1, diagnosis: $diagnosis, message: 'Lenient plugin setup failed: ' . $diagnosis->summary);
        }
        return new StepResult($step, StepResult::DONE, attempts: count($attempts), message: 'Lenient allow-list: ' . implode(', ', $step->packages));
    }
}
