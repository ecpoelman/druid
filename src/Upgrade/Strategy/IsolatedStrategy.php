<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Plan\Step;

/**
 * Rung 1: the smallest possible change (`--with-dependencies --minimal-changes`; core packages together).
 */
final class IsolatedStrategy implements Strategy
{
    public function name(): string
    {
        return 'isolated';
    }

    public function attempts(Step $step, AttemptContext $context): array
    {
        return [$context->builder->isolated($step)];
    }
}
