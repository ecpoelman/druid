<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Composer\ComposerProject;
use Druid\Plan\Step;
use Druid\Security\VersionResolver;

/**
 * Builds Composer attempts for a step, keeping composer.json constraint style intact.
 * Packages living in require-dev stay there (constraints are raised with `--dev --no-update`).
 */
final class CommandBuilder
{
    public const NO_AUDIT_BLOCKING_ENV = [
        'COMPOSER_NO_BLOCKING' => '1',
        'COMPOSER_NO_SECURITY_BLOCKING' => '1',
    ];

    public function __construct(
        private readonly ComposerProject $project,
        private readonly bool $minimalChanges,
    ) {
    }

    /**
     * Environment every attempt for a step starts with. Security steps target a known fixed release,
     * so Composer's insecure-version blocking is bypassed from the first attempt (when enabled).
     *
     * @return array<string, string>
     */
    public static function baseEnv(Step $step, bool $bypassAuditBlockingForSecurity): array
    {
        return $step->isSecurity() && $bypassAuditBlockingForSecurity ? self::NO_AUDIT_BLOCKING_ENV : [];
    }

    /**
     * Most conservative command for a step.
     *
     * Contrib: only this package changes, plus non-root dependencies it strictly needs (`-w`, not `-W`).
     * composer.json stays untouched when its constraint already allows the target.
     * Core: all core packages move together with every dependency (root packages need `-W`).
     */
    public function isolated(Step $step): Attempt
    {
        if ($step->isCore()) {
            return $this->core($step, false);
        }
        $package = (string) $step->package;
        $target = (string) $step->to;
        $constraint = $this->project->constraint($package);

        $args = match (true) {
            $constraint === null && $step->from === null => ['require', $package . ':' . $this->project->constraintFor($package, $target)],
            $constraint === null => ['update', "{$package}:{$target}"],
            ComposerProject::isExactVersionConstraint($constraint) => $this->withDevFlag($package, ['require', "{$package}:{$target}"]),
            !VersionResolver::constraintAllows($constraint, $target) => $this->withDevFlag($package, ['require', $package . ':' . $this->project->constraintFor($package, $target)]),
            default => ['update', "{$package}:{$target}"],
        };
        $args[] = '--with-dependencies';
        if ($this->minimalChanges) {
            $args[] = '--minimal-changes';
        }
        return new Attempt('isolated', 'Isolated update', $args);
    }

    /**
     * Raises the composer.json constraint explicitly. Null for transitive packages (no new root requirements).
     */
    public function requireBump(Step $step): ?Attempt
    {
        if ($step->isCore()) {
            return $this->core($step, true)->withStrategy('require_bump', 'Raise core constraints');
        }
        $package = (string) $step->package;
        if (!$this->project->isDirect($package)) {
            return null;
        }
        $args = $this->withDevFlag($package, ['require', $package . ':' . $this->project->constraintFor($package, (string) $step->to)]);
        $args[] = '--with-dependencies';
        return new Attempt('require_bump', 'Raise the constraint in composer.json', $args);
    }

    /**
     * Updates the package together with blocking packages, letting their dependencies move too.
     *
     * @param list<string> $blockers
     */
    public function withBlockers(Step $step, array $blockers): Attempt
    {
        if ($step->isCore()) {
            $base = $this->core($step, false);
            $args = array_merge(array_slice($base->args, 0, -1), $blockers, ['--with-all-dependencies']);
            return new Attempt('blockers', 'Update core together with: ' . implode(', ', $blockers), $args, [], $base->prerequisites);
        }

        $package = (string) $step->package;
        $target = (string) $step->to;
        $prerequisites = [];
        $constraint = $this->project->constraint($package);
        if ($constraint !== null && !VersionResolver::constraintAllows($constraint, $target)) {
            $prerequisites[] = new Attempt('blockers', 'Raise constraint without updating', $this->requireNoUpdate($package, $this->project->constraintFor($package, $target)));
        }
        $args = array_merge(['update', "{$package}:{$target}"], $blockers, ['--with-dependencies']);
        return new Attempt('blockers', 'Update together with blocking packages: ' . implode(', ', $blockers), $args, [], $prerequisites);
    }

    /**
     * Lets Composer move every dependency, including root packages such as Drupal core (`-W`).
     */
    public function allDependencies(Step $step): Attempt
    {
        if ($step->isCore()) {
            return $this->core($step, true)->withStrategy('all_dependencies', 'Raise core constraints and update all dependencies');
        }
        $package = (string) $step->package;
        $target = (string) $step->to;
        $prerequisites = [];
        $constraint = $this->project->constraint($package);
        if ($constraint !== null && !VersionResolver::constraintAllows($constraint, $target)) {
            $prerequisites[] = new Attempt('all_dependencies', 'Raise constraint without updating', $this->requireNoUpdate($package, $this->project->constraintFor($package, $target)));
        }
        return new Attempt('all_dependencies', 'Update with all dependencies (-W)', ['update', "{$package}:{$target}", '--with-all-dependencies'], [], $prerequisites);
    }

    /**
     * Core update to `$version`, used before a contrib step that needs a newer core.
     */
    public function coreTo(string $version): Attempt
    {
        $step = new Step('core', Step::CORE, Step::SCOPE_MINOR, 'Drupal core', 'drupal/core', null, $version);
        return $this->core($step, false)->withStrategy('core_first', "Update Drupal core to {$version} first");
    }

    /**
     * @return list<string>
     */
    public function requireNoUpdate(string $package, string $constraint): array
    {
        return $this->withDevFlag($package, ['require', "{$package}:{$constraint}", '--no-update']);
    }

    /**
     * Core packages move as one. When every constraint already allows the target, a single
     * `composer update core:VERSION ... -W` keeps composer.json untouched. Otherwise constraints are
     * raised first (`require --no-update`, per section, in their existing style) and then updated.
     */
    private function core(Step $step, bool $forceRequire): Attempt
    {
        $target = (string) $step->to;
        $packages = $this->project->pinnedCorePackages();
        $needsRequire = $forceRequire
            || $step->kind === Step::MAJOR_CORE
            || $step->companions !== []
            || !$this->coreConstraintsAllow($target);

        $update = ['update'];
        foreach ($packages as $package) {
            $update[] = "{$package}:{$target}";
        }

        if (!$needsRequire) {
            $update[] = '--with-all-dependencies';
            return new Attempt('isolated', 'Update all core packages together', $update);
        }

        $sections = ['require' => [], 'require-dev' => []];
        foreach ($packages as $package) {
            $constraint = $this->project->constraint($package);
            $value = $constraint === null || ComposerProject::isExactVersionConstraint($constraint)
                ? $target
                : $this->project->constraintFor($package, $step->kind === Step::MAJOR_CORE ? self::majorMinor($target) : $target);
            $sections[$this->project->isDevRequirement($package) ? 'require-dev' : 'require'][] = "{$package}:{$value}";
        }
        foreach ($step->companions as $package => $constraint) {
            $sections[$this->project->isDevRequirement($package) ? 'require-dev' : 'require'][] = "{$package}:{$constraint}";
            $update[] = $package;
        }

        $prerequisites = [];
        if ($sections['require'] !== []) {
            $prerequisites[] = new Attempt('isolated', 'Set core constraints', array_merge(['require'], $sections['require'], ['--no-update']));
        }
        if ($sections['require-dev'] !== []) {
            $prerequisites[] = new Attempt('isolated', 'Set core dev constraints', array_merge(['require', '--dev'], $sections['require-dev'], ['--no-update']));
        }
        $update[] = '--with-all-dependencies';

        return new Attempt('isolated', 'Raise core constraints and update all core packages together', $update, [], $prerequisites);
    }

    private function coreConstraintsAllow(string $target): bool
    {
        foreach ($this->project->pinnedCorePackages() as $package) {
            $constraint = $this->project->constraint($package);
            if ($constraint !== null && !VersionResolver::constraintAllows($constraint, $target)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function withDevFlag(string $package, array $args): array
    {
        if ($this->project->isDevRequirement($package)) {
            $args[] = '--dev';
        }
        return $args;
    }

    private static function majorMinor(string $version): string
    {
        [$major, $minor] = VersionResolver::parts($version);
        return "{$major}.{$minor}";
    }
}
