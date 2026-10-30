<?php

declare(strict_types=1);

namespace Druid\Plan;

use Druid\Composer\ComposerProject;
use Druid\Drupal\ComposerMetadataClient;
use Druid\Drupal\DrupalVersion;
use Druid\Drupal\InfoFile;
use Druid\Drupal\PhpRequirements;
use Druid\Drupal\RemovedCoreExtensions;
use Druid\Drupal\ReleaseHistory;
use Druid\Drupal\ReleaseHistoryClient;
use Druid\Drush\DrushInfo;
use Druid\Scan\ScanResult;
use Druid\Security\VersionResolver;

/**
 * Plans the move to the next Drupal core major, core first: readiness check, core within its major,
 * lenient fallbacks, the major core step (with the contrib it can't move without), the remaining contrib
 * updates one at a time, then custom modules and themes.
 */
final class MajorPlanner
{
    public function __construct(
        private readonly ComposerProject $project,
        private readonly ReleaseHistoryClient $releaseHistory,
        private readonly ?DrushInfo $drush = null,
        private readonly ?ComposerMetadataClient $composerMetadata = null,
    ) {
    }

    /**
     * @param list<Step> $existing steps already planned (security and minor)
     * @return array{steps: list<Step>, notes: list<string>, target: ?string} steps is the whole run, `$existing` included
     *         (empty when there is no major to move to)
     */
    public function plan(ScanResult $scan, PlanOptions $options, array $existing): array
    {
        $notes = [];
        $bridgeCore = $scan->core->current;
        foreach ($existing as $step) {
            if ($step->kind === Step::CORE && $step->to !== null) {
                $bridgeCore = $step->to;
            }
        }

        $target = $this->resolveTarget($scan, $options->target, $notes);
        if ($target === null) {
            return ['steps' => [], 'notes' => $notes, 'target' => null];
        }
        $targetMajor = VersionResolver::major($target);
        if ($targetMajor <= VersionResolver::major($scan->core->current)) {
            if (VersionResolver::greaterThan($target, $scan->core->current)) {
                $notes[] = "Drupal {$target} is in the current major - use `druid upgrade:core {$target}` instead.";
            } else {
                $notes[] = "Drupal core {$scan->core->current} is already on Drupal {$targetMajor}.";
            }
            return ['steps' => [], 'notes' => $notes, 'target' => null];
        }

        $steps = [new Step(
            'readiness',
            Step::READINESS,
            Step::SCOPE_MAJOR,
            "Drupal {$targetMajor} readiness check",
            null,
            null,
            $target,
            description: 'Runs upgrade_status (when installed) to list deprecated code and incompatible extensions. Informational - it does not stop the run.',
        )];

        // The site's own PHP needs the user. The core step raises config.platform.php; hosting files get a step.
        foreach (PhpRequirements::problems($targetMajor, isset($scan->environment['php']) ? (string) $scan->environment['php'] : null) as $problem) {
            $notes[] = $problem;
        }
        $phpVersion = PhpRequirements::shortMinimum($targetMajor);
        if (PhpRequirements::platformPhpTooLow($targetMajor, $this->project)) {
            $notes[] = 'composer.json pins config.platform.php to ' . PhpRequirements::platformPhp($this->project) . " - the core step raises it to {$phpVersion}.";
        }
        $hosting = PhpRequirements::outdatedHostingPhp($targetMajor, $this->project->root);
        if ($hosting !== []) {
            $steps[] = new Step(
                'php-platform',
                Step::PHP_PLATFORM,
                Step::SCOPE_MAJOR,
                "Set the hosting PHP version to {$phpVersion}",
                null,
                null,
                $target,
                description: "Drupal {$targetMajor} needs PHP {$phpVersion}+ on the server: " . implode(', ', array_map(
                    static fn (string $file, string $version): string => "{$file} has {$version}",
                    array_keys($hosting),
                    $hosting,
                )) . '.',
                packages: array_map(static fn (string $file): string => "edit {$file}: php_version: {$phpVersion}", array_keys($hosting)),
            );
        }

        $planned = [];
        foreach ($existing as $step) {
            if ($step->package !== null && $step->to !== null && !$step->isCore()) {
                $planned[$step->package] = $step->to;
            }
        }

        // Contrib whose release can't run on the new major would block core, so it moves with core.
        $companions = [];
        $lenient = [];
        foreach ($this->compatibility($bridgeCore, $target, $planned) as $package => $decision) {
            $current = $decision['current'];
            if ($decision['version'] !== null && !VersionResolver::isStable($decision['version'])) {
                $notes[] = "{$package} {$decision['version']} is a pre-release (the only one supporting Drupal {$targetMajor}) - it is not covered by security advisories.";
            }
            switch ($decision['action']) {
                case 'unknown':
                    $notes[] = "No drupal.org release data for {$package} - check its Drupal {$targetMajor} support manually.";
                    break;
                case 'bridge':
                case 'companion':
                    $companions[$package] = $this->project->constraintFor($package, (string) $decision['version']);
                    $notes[] = "{$package} {$current} → {$decision['version']} moves together with core ({$current} does not support Drupal {$targetMajor}).";
                    break;
                case 'lenient':
                    $lenient[] = $package;
                    $notes[] = "{$package}: no release declares Drupal {$targetMajor} support yet - it will be allowed through the lenient plugin. Test it carefully or look for a patch.";
                    break;
                case 'unsupported':
                    $notes[] = "{$package} is unsupported on drupal.org - replace or remove it before upgrading.";
                    $lenient[] = $package;
                    break;
            }
        }

        foreach ($existing as $step) {
            if ($step->isCore()) {
                $steps[] = $step;
            }
        }

        if ($lenient !== []) {
            sort($lenient);
            $steps[] = new Step(
                'lenient',
                Step::LENIENT,
                Step::SCOPE_MAJOR,
                'Allow ' . count($lenient) . " module(s) without declared Drupal {$targetMajor} support",
                description: 'Installs mglaman/composer-drupal-lenient and adds them to extra.drupal-lenient.allowed-list.',
                packages: $lenient,
            );
        }

        $fromMajor = VersionResolver::major($scan->core->current);
        foreach (RemovedCoreExtensions::affecting($scan->enabledExtensions ?? [], $fromMajor, $targetMajor) as $extension => $removed) {
            $package = $removed['package'];
            if ($this->project->lockedVersion($package) !== null || isset($companions[$package])) {
                continue;
            }
            $version = $this->releaseSupporting($package, $target);
            if ($version === null) {
                $notes[] = "{$extension} is enabled but Drupal {$removed['major']} removed it from core and {$package} has no Drupal {$targetMajor} release - uninstall it first (`drush pm:uninstall {$extension}`).";
                continue;
            }
            $companions[$package] = '^' . $version;
            $notes[] = "{$extension} is enabled but Drupal {$removed['major']} removed it from core - {$package} {$version} is installed together with core.";
        }

        if ($this->drush !== null && $targetMajor >= 11 && $this->drush->majorVersion() < 13) {
            $companions['drush/drush'] = '^13';
            $notes[] = "Drush {$this->drush->version} does not support Drupal {$targetMajor} - drush/drush moves to ^13 with core.";
        }

        ksort($companions);
        $steps[] = new Step(
            'major-core',
            Step::MAJOR_CORE,
            Step::SCOPE_MAJOR,
            "Drupal core {$bridgeCore} → {$target}" . ($companions !== [] ? ' with ' . count($companions) . ' contrib package(s)' : ''),
            'drupal/core',
            $bridgeCore,
            $target,
            risk: 'high',
            description: 'All core packages move to the new major together' . ($companions !== [] ? ' with ' . implode(', ', array_keys($companions)) : '') . '. Database updates run at the end of the run, once contrib and custom code are on the new major.',
            critical: true,
            companions: $companions,
        );

        // Remaining contrib updates, one at a time on the new core. Companions already moved past them.
        foreach ($existing as $step) {
            if ($step->isCore() || in_array($step->kind, [Step::RECTOR, Step::INFO_FILES], true)) {
                continue;
            }
            if ($step->package !== null && isset($companions[$step->package])) {
                continue;
            }
            $steps[] = $step;
        }

        // Custom code last: it is patched against the core and contrib it will run with.
        if ($options->rector || $scan->customModules !== []) {
            $steps[] = self::rectorStep($scan->customModules, $this->project->root, $options->rector, Step::SCOPE_MAJOR);
        }
        $infoStep = self::infoFilesStep($scan->customModules, $this->project->root, $bridgeCore, $target, Step::SCOPE_MAJOR);
        if ($infoStep !== null) {
            $steps[] = $infoStep;
        }

        return ['steps' => $steps, 'notes' => $notes, 'target' => $target];
    }

    /**
     * Drupal `$target` support of every direct contrib module, theme and profile.
     *
     * @param array<string, string> $planned package => version it moves to before the major step
     * @return array<string, array{current: string, action: 'none'|'bridge'|'companion'|'lenient'|'unsupported'|'unknown', version: ?string}>
     */
    public function compatibility(string $bridgeCore, string $target, array $planned = []): array
    {
        $contrib = [];
        $projects = [];
        foreach ($this->project->lockPackages() as $package) {
            $name = $package['name'];
            if (!in_array($package['type'], ['drupal-module', 'drupal-theme', 'drupal-profile'], true)
                || ComposerProject::isCorePackage($name)
                || !$this->project->isDirect($name)) {
                continue;
            }
            $project = DrupalVersion::projectName($name);
            if ($project === null) {
                continue;
            }
            $contrib[$name] = $planned[$name] ?? $package['version'];
            $projects[$name] = $project;
        }
        ksort($contrib);

        $histories = $this->releaseHistory->fetchMany(array_values($projects));
        $composerCore = $this->composerMetadata?->coreConstraints(array_keys($contrib)) ?? [];
        $locked = $this->project->lockPackages();
        $rows = [];
        foreach ($contrib as $package => $current) {
            $history = $histories[$projects[$package]] ?? null;
            $constraints = $composerCore[$package] ?? [];
            // The lock holds the installed version's real requirement, even when offline.
            if (($locked[$package]['version'] ?? null) === $current && isset($locked[$package]['require']['drupal/core'])) {
                $constraints[$current] = $locked[$package]['require']['drupal/core'];
            }
            $decision = $history === null
                ? ['action' => 'unknown', 'version' => null]
                : self::decide($history, $current, $bridgeCore, $target, $constraints);
            $rows[$package] = ['current' => $current] + $decision;
        }
        return $rows;
    }

    /**
     * Resolves `11`, `11.x`, `11.2` or a full version to a concrete Drupal release (default: next major).
     *
     * @param list<string> $notes
     */
    public function target(ScanResult $scan, ?string $requested, array &$notes = []): ?string
    {
        return $this->resolveTarget($scan, $requested, $notes);
    }

    /**
     * What to do with one contrib package for the major upgrade. Preference: a stable release that works on
     * both majors (bridge), a stable release for the new major only (companion), then the same with
     * pre-releases, then the lenient plugin.
     *
     * @param array<string, ?string> $composerCore version => drupal/core constraint from Composer metadata
     * @return array{action: 'none'|'bridge'|'companion'|'lenient'|'unsupported', version: ?string}
     */
    public static function decide(ReleaseHistory $history, string $current, string $bridgeCore, string $target, array $composerCore = []): array
    {
        if ($history->isUnsupported()) {
            return ['action' => 'unsupported', 'version' => null];
        }
        $installed = $history->release($current);
        $installedSupportsTarget = $installed !== null
            ? $installed->supportsCore($target, $composerCore)
            : array_key_exists($current, $composerCore) && ($composerCore[$current] === null || VersionResolver::satisfies($target, $composerCore[$current]));
        if ($installedSupportsTarget) {
            return ['action' => 'none', 'version' => null];
        }

        $newerOrSame = static fn (array $releases): array => array_values(array_filter(
            $releases,
            static fn ($release): bool => VersionResolver::compare($release->version, $current) >= 0,
        ));

        foreach ([true, false] as $stableOnly) {
            $bridge = $newerOrSame($history->releasesSupportingCore([$bridgeCore, $target], $stableOnly, $composerCore));
            if ($bridge !== []) {
                $pick = self::preferSameMajor($bridge, $current);
                return ['action' => VersionResolver::compare($pick, $current) === 0 ? 'none' : 'bridge', 'version' => $pick];
            }
            $forward = $newerOrSame($history->releasesSupportingCore([$target], $stableOnly, $composerCore));
            if ($forward !== []) {
                return ['action' => 'companion', 'version' => self::preferSameMajor($forward, $current)];
            }
        }
        return ['action' => 'lenient', 'version' => null];
    }

    /**
     * @param array<string, string> $customModules name => directory
     */
    public static function rectorStep(array $customModules, string $root, bool $apply, string $scope): Step
    {
        $root = rtrim($root, '/') . '/';
        return new Step(
            'rector',
            Step::RECTOR,
            $scope,
            'Fix deprecated code in custom modules (drupal-rector)',
            description: $customModules === []
                ? 'No custom modules found.'
                : 'Custom code: ' . implode(', ', array_keys($customModules)) . ($apply ? '.' : '. Applied only with --rector; otherwise a dry run shows the changes.'),
            packages: array_values(array_map(
                static fn (string $path): string => str_starts_with($path, $root) ? substr($path, strlen($root)) : $path,
                $customModules,
            )),
        );
    }

    /**
     * A step that adds Drupal `$target`'s major to the custom extensions whose .info.yml excludes it.
     *
     * @param array<string, string> $customModules name => directory
     */
    public static function infoFilesStep(array $customModules, string $root, ?string $from, string $target, string $scope): ?Step
    {
        $root = rtrim($root, '/') . '/';
        $files = [];
        $names = [];
        foreach ($customModules as $name => $directory) {
            $file = InfoFile::forExtension($name, $directory);
            if ($file === null || InfoFile::supports($file, $target)) {
                continue;
            }
            $files[] = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
            $names[] = $name;
        }
        if ($files === []) {
            return null;
        }
        $major = VersionResolver::major($target);
        return new Step(
            'info-files',
            Step::INFO_FILES,
            $scope,
            "Declare Drupal {$major} support in " . count($files) . ' custom extension(s)',
            null,
            $from,
            $target,
            description: 'Adds ^' . $major . ' to core_version_requirement in ' . implode(', ', $names) . ' so Drupal does not mark them incompatible. Deprecated code is checked by `druid upgrade:check` and fixed with --rector.',
            packages: $files,
        );
    }

    /**
     * Newest release of a drupal.org package that supports `$core` (stable preferred).
     */
    public function releaseSupporting(string $package, string $core): ?string
    {
        $project = DrupalVersion::projectName($package);
        $history = $project !== null ? $this->releaseHistory->fetch($project) : null;
        if ($history === null) {
            return null;
        }
        $composerCore = $this->composerMetadata?->coreConstraints([$package])[$package] ?? [];
        $releases = $history->releasesSupportingCore([$core], true, $composerCore)
            ?: $history->releasesSupportingCore([$core], false, $composerCore);
        return $releases[0]->version ?? null;
    }

    /**
     * Newest release in the installed major line, otherwise the newest overall.
     *
     * @param list<\Druid\Drupal\Release> $releases newest first
     */
    private static function preferSameMajor(array $releases, string $current): string
    {
        foreach ($releases as $release) {
            if (VersionResolver::sameMajor($release->version, $current)) {
                return $release->version;
            }
        }
        return $releases[0]->version;
    }

    /**
     * @param list<string> $notes
     */
    private function resolveTarget(ScanResult $scan, ?string $requested, array &$notes): ?string
    {
        if ($requested !== null && preg_match('/^\d+\.\d+\.\d+/', $requested) === 1) {
            return $requested;
        }

        $major = null;
        $minor = null;
        if ($requested !== null && preg_match('/^(\d+)(?:\.(x|\d+))?$/', $requested, $m) === 1) {
            $major = (int) $m[1];
            $minor = isset($m[2]) && $m[2] !== 'x' ? (int) $m[2] : null;
        }
        if ($major === null && $scan->core->nextMajor !== null) {
            return $scan->core->nextMajor;
        }

        $history = $this->releaseHistory->fetch('drupal');
        $major ??= VersionResolver::major($scan->core->current) + 1;
        $version = $history === null ? null : VersionResolver::latestStable($history->versions(), $major, $minor);
        if ($version === null) {
            $notes[] = "No stable Drupal {$major}" . ($minor !== null ? ".{$minor}" : '') . ' release found on drupal.org.';
        }
        return $version;
    }
}
