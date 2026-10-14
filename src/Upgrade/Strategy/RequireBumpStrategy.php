<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Plan\Step;

/**
 * Rung 2: raise the composer.json constraint explicitly, in the style it already uses.
 */
final class RequireBumpStrategy implements Strategy
{
    public function name(): string
    {
        return 'require_bump';
    }

    public function attempts(Step $step, AttemptContext $context): array
    {
        $attempt = $context->builder->requireBump($step);
        return $attempt === null ? [] : [$attempt];
    }
}
