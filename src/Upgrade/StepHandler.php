<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Plan\Step;

/**
 * Executes non-Composer plan steps (readiness checks, lenient setup, rector).
 */
interface StepHandler
{
    public function supports(Step $step): bool;

    public function handle(Step $step, EngineListener $listener): StepResult;
}
