<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Plan\Step;
use Druid\Security\VersionResolver;
use Druid\Upgrade\Attempt;
use Druid\Upgrade\Diagnosis;

/**
 * Rung 6: a contrib release needs a newer core - update core within its current major first, then retry.
 */
final class CoreFirstStrategy implements Strategy
{
    public function name(): string
    {
        return 'core_first';
    }

    public function attempts(Step $step, AttemptContext $context): array
    {
        if ($step->isCore() || !$context->sawKind(Diagnosis::NEEDS_NEWER_CORE)) {
            return [];
        }

        $current = $context->project->installedCoreVersion();
        $core = $context->scan?->core;
        if ($current === null || $core === null) {
            return [];
        }

        $targets = array_values(array_unique(array_filter(
            [$core->latestPatch, $core->latestMinor],
            static fn (?string $v): bool => $v !== null && VersionResolver::greaterThan($v, $current),
        )));

        $attempts = [];
        foreach ($targets as $coreTarget) {
            $coreAttempt = $context->builder->coreTo($coreTarget);
            $module = $context->builder->isolated($step);
            $attempts[] = new Attempt(
                $this->name(),
                "Update Drupal core to {$coreTarget}, then {$step->package}",
                $module->args,
                $module->env,
                array_merge($coreAttempt->prerequisites, [new Attempt($this->name(), "Drupal core {$coreTarget}", $coreAttempt->args, $coreAttempt->env)], $module->prerequisites),
            );
        }
        return $attempts;
    }
}
