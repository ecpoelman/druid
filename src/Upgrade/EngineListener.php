<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Plan\Step;
use Druid\Plan\UpgradePlan;

/**
 * Receives engine progress (console output, logs, tests).
 */
interface EngineListener
{
    public function runStarted(UpgradePlan $plan): void;

    /** Preflight or post-run message. $level is ok, warn, fail or info. */
    public function notice(string $level, string $message): void;

    public function stepStarted(int $number, int $total, Step $step): void;

    public function attemptStarted(int $number, Attempt $attempt, string $display): void;

    public function output(string $buffer, bool $isError): void;

    public function attemptFailed(Diagnosis $diagnosis, string $output): void;

    public function stepFinished(StepResult $result): void;

    public function runFinished(RunResult $result): void;
}
