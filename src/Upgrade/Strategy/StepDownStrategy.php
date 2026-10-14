<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Plan\Step;
use Druid\Security\VersionResolver;

/**
 * Rung 7: try other safe releases near the target - lower ones first (closest to the target),
 * then, for security steps, newer patch releases that may have fixed compatibility.
 */
final class StepDownStrategy implements Strategy
{
    public function __construct(private readonly int $maxCandidates = 3)
    {
    }

    public function name(): string
    {
        return 'step_down';
    }

    public function attempts(Step $step, AttemptContext $context): array
    {
        if ($step->package === null || $step->from === null || $step->to === null) {
            return [];
        }
        $releases = $context->composer->releases($step->isCore() ? 'drupal/core' : $step->package);
        $attempts = [];
        foreach (self::candidates($step, $releases, $this->maxCandidates) as $candidate) {
            $attempts[] = $context->builder->allDependencies($step->withTarget($candidate))
                ->withStrategy($this->name(), "Try {$candidate} instead of {$step->to}");
        }
        return $attempts;
    }

    /**
     * @param list<string> $releases
     * @return list<string>
     */
    public static function candidates(Step $step, array $releases, int $max): array
    {
        $from = (string) $step->from;
        $to = (string) $step->to;
        $safe = array_values(array_filter(
            $releases,
            static fn (string $v): bool => VersionResolver::isStable($v)
                && VersionResolver::greaterThan($v, $from)
                && !VersionResolver::isAffectedByAny($v, $step->affected)
                && VersionResolver::compare($v, $to) !== 0,
        ));

        $lower = array_values(array_filter($safe, static fn (string $v): bool => VersionResolver::greaterThan($to, $v)));
        $lower = array_slice(VersionResolver::sort($lower, true), 0, $max);

        $higher = [];
        if ($step->isSecurity()) {
            $higher = array_values(array_filter(
                $safe,
                static fn (string $v): bool => VersionResolver::greaterThan($v, $to) && VersionResolver::sameMajor($v, $to),
            ));
            $higher = array_slice(VersionResolver::sort($higher), 0, $max);
        }

        return array_merge($lower, $higher);
    }
}
