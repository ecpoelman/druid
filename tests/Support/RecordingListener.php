<?php

declare(strict_types=1);

namespace Druid\Tests\Support;

use Druid\Plan\Step;
use Druid\Plan\UpgradePlan;
use Druid\Upgrade\Attempt;
use Druid\Upgrade\Diagnosis;
use Druid\Upgrade\EngineListener;
use Druid\Upgrade\RunResult;
use Druid\Upgrade\StepResult;

/**
 * EngineListener that records events for assertions.
 */
final class RecordingListener implements EngineListener
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<string> strategy names in attempt order */
    public array $strategies = [];

    public function runStarted(UpgradePlan $plan): void
    {
        $this->events[] = 'run';
    }

    public function notice(string $level, string $message): void
    {
        $this->events[] = "{$level}: {$message}";
    }

    public function stepStarted(int $number, int $total, Step $step): void
    {
        $this->events[] = "step {$number}/{$total} {$step->id}";
    }

    public function attemptStarted(int $number, Attempt $attempt, string $display): void
    {
        $this->strategies[] = $attempt->strategy;
        $this->events[] = "attempt {$number} {$attempt->strategy}";
    }

    public function output(string $buffer, bool $isError): void
    {
    }

    public function attemptFailed(Diagnosis $diagnosis, string $output): void
    {
        $this->events[] = "failed {$diagnosis->kind}";
    }

    public function stepFinished(StepResult $result): void
    {
        $this->events[] = "finished {$result->step->id} {$result->status}";
    }

    public function runFinished(RunResult $result): void
    {
        $this->events[] = 'done';
    }
}
