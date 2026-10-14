<?php

declare(strict_types=1);

namespace Druid\Plan;

use Druid\Composer\ComposerProject;
use Druid\Config\Config;
use Druid\Drupal\RemovedCoreExtensions;
use Druid\Scan\PackageStatus;
use Druid\Scan\ScanResult;
use Druid\Security\Advisory;
use Druid\Security\Severity;
use Druid\Security\VersionResolver;

/**
 * Turns a scan into an ordered upgrade plan. The recommended target is always the safest one:
 * the lowest release that clears every advisory, inside the current major, unless asked otherwise.
 */
final class Planner
{
    public function __construct(
        private readonly Config $config,
        private readonly ComposerProject $project,
        private readonly ?MajorPlanner $majorPlanner = null,
    ) {
    }

    public function build(ScanResult $scan, PlanOptions $options): UpgradePlan
    {
        $notes = [];
        $steps = [];
        $excluded = array_map('strtolower', $this->config->list('packages.exclude'));
        $pins = (array) $this->config->get('packages.pin', []);

        $wantsCore = $options->includeCore && ($options->packages === [] || $options->wants('drupal/core'));
        if ($wantsCore) {
            $core = $this->coreStep($scan, $options, $pins, $notes);
            if ($core !== null) {
                $steps[] = $core;
            }
        }

        if ($options->includeContrib) {
            $seen = [];
            foreach ($scan->packages as $package) {
                if (!$options->wants($package->package)) {
                    continue;
                }
                $seen[] = $package->package;
                if (in_array(strtolower($package->package), $excluded, true)) {
                    $notes[] = "{$package->package} is excluded in druid.yml (packages.exclude).";
                    continue;
                }
                $step = $this->packageStep($package, $options, $pins, $notes);
                if ($step !== null) {
                    $steps[] = $step;
                }
            }
            foreach ($options->packages as $wanted) {
                $steps = array_merge($steps, $this->unscannedPackageStep($wanted, $seen, $options, $notes));
            }
            $steps = array_merge($steps, $this->missingExtensionSteps($scan, $options, $notes));
            if ($options->packages === [] && VersionResolver::isRelease($scan->core->current)) {
                if ($options->rector && !$options->includesMajor() && $scan->customModules !== []) {
                    $steps[] = MajorPlanner::rectorStep($scan->customModules, $this->project->root, true, Step::SCOPE_MINOR);
                }
                $infoStep = MajorPlanner::infoFilesStep($scan->customModules, $this->project->root, null, $scan->core->current, Step::SCOPE_MINOR);
                if ($infoStep !== null) {
                    $steps[] = $infoStep;
                }
            }
        }

        $steps = self::order($steps);
        $targetCore = null;

        if ($options->includesMajor() && $wantsCore && $this->majorPlanner !== null) {
            $major = $this->majorPlanner->plan($scan, $options, $steps);
            $steps = array_merge($steps, $major['steps']);
            $notes = array_merge($notes, $major['notes']);
            $targetCore = $major['target'];
        }

        return new UpgradePlan($scan->site, $options->scope, array_values($steps), array_values(array_unique($notes)), $targetCore);
    }

    /**
     * @param array<string, mixed> $pins
     * @param list<string> $notes
     */
    private function coreStep(ScanResult $scan, PlanOptions $options, array $pins, array &$notes): ?Step
    {
        $core = $scan->core;
        $current = $core->current;
        if (!VersionResolver::isRelease($current)) {
            return null;
        }

        $explicit = !$options->includeContrib && $options->target !== null && substr_count($options->target, '.') >= 2 ? $options->target : null;
        $target = match (true) {
            $explicit !== null => $explicit,
            $options->includesMinor() => self::max([$core->securityTarget, $core->latestPatch, $core->latestMinor]),
            $options->latest => self::max([$core->securityTarget, $core->securityTarget !== null ? $core->latestPatch : null]),
            default => $core->securityTarget,
        };

        if ($target === null || !VersionResolver::greaterThan($target, $current)) {
            if ($core->securityTarget === null && $core->advisories !== []) {
                $notes[] = 'Core has open advisories but no fixed release could be determined. Check https://www.drupal.org/security.';
            }
            return null;
        }

        $pin = $pins['drupal/core'] ?? null;
        if (is_string($pin) && !VersionResolver::satisfies($target, $pin)) {
            $notes[] = "Drupal core {$target} is outside the pinned constraint {$pin} (packages.pin) - skipped.";
            return null;
        }

        $isSecurity = $core->securityTarget !== null;
        $packages = $this->project->pinnedCorePackages();
        return new Step(
            'core',
            Step::CORE,
            $isSecurity ? Step::SCOPE_SECURITY : Step::SCOPE_MINOR,
            "Drupal core {$current} → {$target}",
            'drupal/core',
            $current,
            $target,
            $isSecurity ? $core->severity() : Severity::None,
            VersionResolver::sameMinor($current, $target) ? 'low' : 'medium',
            sprintf('Moves all %d core packages together (%s). Contrib stays locked until this succeeds.', count($packages), implode(', ', $packages)),
            true,
            array_values(array_filter(array_map(static fn (Advisory $a): ?string => $a->affectedVersions, $core->advisories))),
        );
    }

    /**
     * @param array<string, mixed> $pins
     * @param list<string> $notes
     */
    private function packageStep(PackageStatus $package, PlanOptions $options, array $pins, array &$notes): ?Step
    {
        $explicitTarget = count($options->packages) === 1 && $options->target !== null ? $options->target : null;
        $scope = Step::SCOPE_MINOR;
        $target = null;

        if ($explicitTarget !== null) {
            $target = $explicitTarget;
            $scope = $package->advisories !== [] ? Step::SCOPE_SECURITY : (VersionResolver::sameMajor($target, $package->current) ? Step::SCOPE_MINOR : Step::SCOPE_MAJOR);
        } elseif ($package->advisories !== []) {
            $scope = Step::SCOPE_SECURITY;
            $target = $package->securityTarget;
            if ($target === null) {
                $ids = implode(', ', array_map(static fn (Advisory $a): string => $a->id, $package->advisories));
                $notes[] = "No fixed release of {$package->package} exists yet ({$ids}). Consider disabling or removing it until one is published.";
                return null;
            }
            if ($options->latest || $options->includesMinor()) {
                $target = self::max([$target, $package->latestMinor]);
            }
        } elseif ($options->includesMinor() && $package->hasMinorUpdate() && ($package->direct || $options->packages !== [])) {
            $target = $package->latestMinor;
        } elseif ($options->packages !== [] && $options->includesMajor() && $package->hasMajorUpdate()) {
            $scope = Step::SCOPE_MAJOR;
            $target = $package->latestMajor;
        } elseif ($options->packages !== [] && $package->hasMinorUpdate()) {
            $target = $package->latestMinor;
        }

        if ($target === null) {
            if ($options->packages !== [] && $package->hasMajorUpdate()) {
                $notes[] = "{$package->package} is on the newest {$package->current} line; {$package->latestMajor} is a new major - use --major to move to it.";
            } elseif ($options->packages !== []) {
                $notes[] = "{$package->package} {$package->current} is already up to date.";
            }
            return null;
        }
        if (!VersionResolver::greaterThan($target, $package->current)) {
            $notes[] = "{$package->package} {$package->current} is already at or above {$target}.";
            return null;
        }

        $pin = $pins[$package->package] ?? null;
        if (is_string($pin) && !VersionResolver::satisfies($target, $pin)) {
            $notes[] = "{$package->package} {$target} is outside the pinned constraint {$pin} (packages.pin) - skipped.";
            return null;
        }

        $risk = match (true) {
            VersionResolver::sameMinor($package->current, $target) => 'low',
            VersionResolver::sameMajor($package->current, $target) => 'medium',
            default => 'high',
        };
        $kind = $package->isDrupal() ? Step::MODULE : Step::LIBRARY;
        $description = match ($scope) {
            Step::SCOPE_SECURITY => $package->direct
                ? 'Security fix. Isolated update: only this package and what it strictly needs change.'
                : 'Security fix for a dependency. Updated in place without adding it to composer.json.',
            Step::SCOPE_MAJOR => 'New major version - review the release notes for config or API changes.',
            default => 'Newer release in the same major line.',
        };
        if ($package->enabled === false) {
            $description .= ' Not enabled on the site - consider removing it instead.';
        }

        return new Step(
            'package:' . $package->package,
            $kind,
            $scope,
            "{$package->name()} {$package->current} → {$target}",
            $package->package,
            $package->current,
            $target,
            $package->severity(),
            $risk,
            $description,
            false,
            $package->affectedRanges(),
            [],
            $package->direct,
        );
    }

    /**
     * Installs the contrib replacement of core extensions that are installed on the site but whose code
     * a major upgrade removed (e.g. tour after Drupal 11).
     *
     * @param list<string> $notes
     * @return list<Step>
     */
    private function missingExtensionSteps(ScanResult $scan, PlanOptions $options, array &$notes): array
    {
        $steps = [];
        foreach ($scan->missingExtensions as $extension) {
            $replacement = RemovedCoreExtensions::replacement($extension);
            if ($replacement === null) {
                $notes[] = "{$extension} is installed on the site but its code is missing - restore it or run `drush pm:uninstall {$extension}`.";
                continue;
            }
            $package = $replacement['package'];
            if (!$options->wants($package) || $this->project->lockedVersion($package) !== null) {
                continue;
            }
            $version = $this->majorPlanner?->releaseSupporting($package, $scan->core->current);
            if ($version === null) {
                $notes[] = "{$extension} is installed but missing, and {$package} has no release for Drupal {$scan->core->current} - uninstall it (`drush pm:uninstall {$extension}`).";
                continue;
            }
            $steps[] = new Step(
                'package:' . $package,
                Step::MODULE,
                Step::SCOPE_MINOR,
                "Restore {$extension}: install {$package} {$version}",
                $package,
                null,
                $version,
                risk: 'low',
                description: "{$extension} is installed on the site but Drupal {$replacement['major']} removed it from core; the contrib project replaces it.",
                critical: true,
            );
        }
        return $steps;
    }

    /**
     * Steps for explicitly requested packages the scan didn't list (e.g. up-to-date libraries).
     *
     * @param list<string> $seen
     * @param list<string> $notes
     * @return list<Step>
     */
    private function unscannedPackageStep(string $wanted, array $seen, PlanOptions $options, array &$notes): array
    {
        $candidates = str_contains($wanted, '/') ? [$wanted] : ["drupal/{$wanted}", $wanted];
        foreach ($candidates as $package) {
            if (in_array($package, $seen, true) || $package === 'drupal/core') {
                return [];
            }
        }
        foreach ($candidates as $package) {
            $current = $this->project->lockedVersion($package);
            if ($current === null) {
                continue;
            }
            if ($options->target === null || !VersionResolver::greaterThan($options->target, $current)) {
                $notes[] = "{$package} {$current} has no newer release in scope.";
                return [];
            }
            return [new Step(
                'package:' . $package,
                str_starts_with($package, 'drupal/') ? Step::MODULE : Step::LIBRARY,
                VersionResolver::sameMajor($current, $options->target) ? Step::SCOPE_MINOR : Step::SCOPE_MAJOR,
                "{$package} {$current} → {$options->target}",
                $package,
                $current,
                $options->target,
                Severity::None,
                VersionResolver::sameMajor($current, $options->target) ? 'medium' : 'high',
                'Explicitly requested update.',
                false,
                [],
                [],
                $this->project->isDirect($package),
            )];
        }
        $notes[] = "{$wanted} is not installed in this project.";
        return [];
    }

    /**
     * Core security, contrib security, library security, core minor, contrib minor, then major.
     *
     * @param list<Step> $steps
     * @return list<Step>
     */
    public static function order(array $steps): array
    {
        $rank = static function (Step $step): int {
            $base = match ($step->scope) {
                Step::SCOPE_SECURITY => 0,
                Step::SCOPE_MINOR => 3,
                default => 6,
            };
            return $base + match ($step->kind) {
                Step::CORE, Step::MAJOR_CORE => 0,
                Step::MODULE => 1,
                default => 2,
            };
        };
        usort($steps, static fn (Step $a, Step $b): int => $rank($a) <=> $rank($b)
            ?: $b->severity->rank() <=> $a->severity->rank()
            ?: strcmp((string) $a->package, (string) $b->package));
        return $steps;
    }

    /**
     * @param list<string|null> $versions
     */
    private static function max(array $versions): ?string
    {
        $best = null;
        foreach ($versions as $version) {
            if ($version !== null && ($best === null || VersionResolver::greaterThan($version, $best))) {
                $best = $version;
            }
        }
        return $best;
    }
}
