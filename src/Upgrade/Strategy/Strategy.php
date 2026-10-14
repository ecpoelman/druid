<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Plan\Step;
use Druid\Upgrade\Attempt;

/**
 * One rung of the upgrade ladder. Returns zero or more attempts to try for a step,
 * using what earlier failures revealed.
 */
interface Strategy
{
    /** Config name, as used in `upgrade.strategies`. */
    public function name(): string;

    /**
     * @return list<Attempt>
     */
    public function attempts(Step $step, AttemptContext $context): array;
}
