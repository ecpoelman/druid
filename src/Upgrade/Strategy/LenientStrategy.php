<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Composer\ComposerProject;
use Druid\Plan\Step;
use Druid\Upgrade\Attempt;
use Druid\Upgrade\Diagnosis;
use Druid\Upgrade\LenientConfigurator;

/**
 * Rung 8 (major upgrades only): allow contrib that hasn't declared support for the new core yet
 * through mglaman/composer-drupal-lenient, then retry with all dependencies.
 */
final class LenientStrategy implements Strategy
{
    public function name(): string
    {
        return 'lenient';
    }

    public function attempts(Step $step, AttemptContext $context): array
    {
        if ($step->scope !== Step::SCOPE_MAJOR) {
            return [];
        }

        $packages = $step->kind === Step::MAJOR_CORE
            ? array_values(array_filter(
                $context->blockers(),
                static fn (string $p): bool => str_starts_with($p, 'drupal/') && !ComposerProject::isCorePackage($p),
            ))
            : array_filter([(string) $step->package]);

        if ($packages === [] || ($step->kind === Step::MAJOR_CORE && !$context->sawKind(Diagnosis::CONTRIB_BLOCKS_CORE) && !$context->sawKind(Diagnosis::DEPENDENCY_CONFLICT))) {
            return [];
        }

        $setup = LenientConfigurator::setupAttempts($context->project, $packages);
        $retry = $context->builder->allDependencies($step);
        return [new Attempt(
            $this->name(),
            'Allow ' . implode(', ', $packages) . ' through the lenient plugin, then update with all dependencies',
            $retry->args,
            $retry->env,
            array_merge($setup, $retry->prerequisites),
        )];
    }
}
