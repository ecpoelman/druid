<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Plan\Step;
use Druid\Upgrade\CommandBuilder;
use Druid\Upgrade\Diagnosis;

/**
 * Rung 5: when Composer's audit blocks insecure versions mid-resolution, retry with blocking bypassed.
 * The target itself is a known fixed release, so the end state is not insecure.
 */
final class NoAuditBlockingStrategy implements Strategy
{
    public function name(): string
    {
        return 'no_audit_blocking';
    }

    public function attempts(Step $step, AttemptContext $context): array
    {
        if (!$context->sawKind(Diagnosis::AUDIT_BLOCKED) && !$this->mentionsAdvisories($context)) {
            return [];
        }
        return [
            $context->builder->isolated($step)
                ->withEnv(CommandBuilder::NO_AUDIT_BLOCKING_ENV)
                ->withStrategy($this->name(), 'Isolated update with audit blocking bypassed'),
            $context->builder->allDependencies($step)
                ->withEnv(CommandBuilder::NO_AUDIT_BLOCKING_ENV)
                ->withStrategy($this->name(), 'All dependencies with audit blocking bypassed'),
        ];
    }

    private function mentionsAdvisories(AttemptContext $context): bool
    {
        foreach ($context->diagnoses as $diagnosis) {
            if ($diagnosis->advisoryIds !== []) {
                return true;
            }
        }
        return false;
    }
}
