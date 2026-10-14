<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Composer\ComposerProject;
use Druid\Composer\ProblemParser;
use Druid\Plan\Step;

/**
 * Rung 3: find what blocks the update (resolver problems + `composer why-not`) and update those packages together.
 */
final class BlockersStrategy implements Strategy
{
    private const MAX_BLOCKERS = 12;

    public function name(): string
    {
        return 'blockers';
    }

    public function attempts(Step $step, AttemptContext $context): array
    {
        if ($step->package === null || $step->to === null) {
            return [];
        }

        $ignore = array_filter([$context->project->name(), $step->package]);
        $blockers = $context->blockers();

        $whyNot = $context->composer->whyNot($step->isCore() ? 'drupal/core' : $step->package, $step->to);
        $blockers = array_merge($blockers, ProblemParser::blockers($whyNot->combined(), $ignore));
        $blockers = array_merge(self::hiddenBlockers($context), $blockers);

        $blockers = array_values(array_unique(array_filter(
            $blockers,
            static function (string $package) use ($ignore): bool {
                if (in_array($package, $ignore, true)) {
                    return false;
                }
                // Core moving is the core_first strategy's job; core steps already include every core package.
                return !ComposerProject::isCorePackage($package);
            },
        )));

        if ($blockers === []) {
            return [];
        }
        return [$context->builder->withBlockers($step, array_slice($blockers, 0, self::MAX_BLOCKERS))];
    }

    /**
     * Locked packages that hold back a dependency Composer "found but did not load" - often a transitive
     * package (e.g. chi-teck/drupal-code-generator pinning symfony/console 6) that -W does not release.
     *
     * @return list<string>
     */
    public static function hiddenBlockers(AttemptContext $context): array
    {
        $found = [];
        foreach ($context->outputs as $output) {
            $found += ProblemParser::notLoaded($output);
        }
        $requirers = [];
        foreach ($found as $package => $version) {
            $requirers = array_merge($requirers, $context->project->lockedRequirersExcluding($package, $version));
        }
        return array_values(array_unique($requirers));
    }
}
