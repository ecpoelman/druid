<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Plan\Step;

/**
 * Rung 4: let Composer move every dependency, including root packages (`--with-all-dependencies`).
 */
final class AllDependenciesStrategy implements Strategy
{
    public function name(): string
    {
        return 'all_dependencies';
    }

    public function attempts(Step $step, AttemptContext $context): array
    {
        return [$context->builder->allDependencies($step)];
    }
}
