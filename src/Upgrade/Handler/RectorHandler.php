<?php

declare(strict_types=1);

namespace Druid\Upgrade\Handler;

use Druid\Major\RectorRunner;
use Druid\Plan\Step;
use Druid\Upgrade\Diagnosis;
use Druid\Upgrade\EngineListener;
use Druid\Upgrade\FailureAnalyzer;
use Druid\Upgrade\StepHandler;
use Druid\Upgrade\StepResult;

/**
 * Applies drupal-rector to custom modules when --rector is given; otherwise shows what a run would do.
 */
final class RectorHandler implements StepHandler
{
    public function __construct(
        private readonly RectorRunner $rector,
        private readonly bool $apply,
    ) {
    }

    public function supports(Step $step): bool
    {
        return $step->kind === Step::RECTOR;
    }

    public function handle(Step $step, EngineListener $listener): StepResult
    {
        if ($step->packages === []) {
            return new StepResult($step, StepResult::SKIPPED, message: 'No custom modules to process.');
        }
        if (!$this->apply) {
            $listener->notice('info', 'Preview rector changes with: ' . $this->rector->display($step->packages, true));
            return new StepResult($step, StepResult::SKIPPED, message: 'Rector not applied (pass --rector to apply it).');
        }

        if (!$this->rector->isInstalled()) {
            $listener->notice('info', 'Installing ' . RectorRunner::PACKAGE . ' as a dev dependency');
            $install = $this->rector->install();
            if (!$install->successful()) {
                $diagnosis = new Diagnosis(Diagnosis::DEPENDENCY_CONFLICT, 'Could not install drupal-rector: ' . FailureAnalyzer::summary($install->combined()));
                return new StepResult($step, StepResult::DEFERRED, attempts: 1, diagnosis: $diagnosis, message: $diagnosis->summary);
            }
        }
        if (!$this->rector->ensureConfig()) {
            $listener->notice('warn', 'No rector.php found and the drupal-rector sample is missing - using rector defaults.');
        }

        $result = $this->rector->run($step->packages, false);
        if (!$result->successful()) {
            $diagnosis = new Diagnosis(Diagnosis::UNKNOWN, 'Rector failed: ' . FailureAnalyzer::summary($result->combined()), ['Run it by hand: ' . $this->rector->display($step->packages, true)]);
            return new StepResult($step, StepResult::DEFERRED, attempts: 1, diagnosis: $diagnosis, message: $diagnosis->summary);
        }
        return new StepResult($step, StepResult::DONE, attempts: 1, message: 'Rector processed ' . implode(', ', $step->packages) . ' - review the changes with `git diff`.');
    }
}
