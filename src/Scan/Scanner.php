<?php

declare(strict_types=1);

namespace Druid\Scan;

use Druid\Composer\ComposerProject;
use Druid\Drupal\DrupalVersion;
use Druid\Drupal\RemovedCoreExtensions;
use Druid\Drupal\ReleaseHistory;
use Druid\Drupal\ReleaseHistoryClient;
use Druid\Security\Advisory;
use Druid\Security\AdvisoryCollector;
use Druid\Security\VersionResolver;
use Druid\Site\DrupalProjectDetector;
use Druid\SiteContext;

/**
 * Scans a site: composer audit, composer outdated, drupal.org release history, Drush status.
 */
final class Scanner
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly SiteContext $context,
        private readonly ReleaseHistoryClient $releaseHistory,
    ) {
    }

    /**
     * @param (callable(string): void)|null $progress
     */
    public function scan(bool $refresh = false, ?callable $progress = null): ScanResult
    {
        $this->warnings = [];
        $progress ??= static function (string $message): void {
        };
        $context = $this->context;
        $project = $context->project;
        $config = $context->config;

        if (!$project->hasLock()) {
            $this->warnings[] = 'composer.lock is missing - run `composer install` so installed versions are known.';
        }

        $progress('Reading composer.lock');
        $lock = $project->lockPackages();
        $installed = array_map(static fn (array $p): string => $p['version'], $lock);
        $coreVersion = $project->installedCoreVersion() ?? '?';

        $progress('Asking Composer for advisories and newer releases (composer audit + outdated, in parallel)');
        $queries = $context->composer->scanQueries();
        $outdated = $queries['outdated'];
        $outdatedMinor = $queries['minor'];

        $progress('Checking security advisories' . ($context->drush !== null && $config->bool('scan.drush_security', true) ? ' (drush pm:security)' : ''));
        $collector = new AdvisoryCollector($context->composer, $config->bool('scan.drush_security', true) ? $context->drush : null);
        $advisories = $collector->collect($installed, $queries['audit']);
        $abandoned = $collector->abandoned();

        $coreAdvisories = [];
        foreach ($advisories as $package => $items) {
            if (ComposerProject::isCorePackage($package)) {
                foreach ($items as $advisory) {
                    if ($coreVersion === '?' || $advisory->affectedVersions === null || $advisory->affects($coreVersion)) {
                        $coreAdvisories[$advisory->id] = $advisory;
                    }
                }
                unset($advisories[$package]);
            }
        }

        $histories = [];
        if ($config->bool('scan.release_history', true)) {
            $progress('Fetching drupal.org release history');
            $projects = ['drupal'];
            foreach ($lock as $package => $info) {
                $name = DrupalVersion::projectName($package);
                if ($name !== null && $name !== 'drupal' && str_starts_with($info['type'], 'drupal-')) {
                    $projects[] = $name;
                }
            }
            $histories = $this->releaseHistory->fetchMany($projects, $refresh);
            foreach (array_slice($this->releaseHistory->errors(), 0, 3) as $error) {
                $this->warnings[] = "Release history unavailable for {$error}";
            }
        }

        $environment = $this->environmentInfo($progress);
        $enabledProjects = null;
        $enabledExtensions = null;
        $missingExtensions = [];
        if ($context->drush !== null && $config->bool('scan.check_enabled', true) && $environment['bootstrap'] === true) {
            $progress('Listing enabled extensions (drush pm:list)');
            $present = $context->drush->enabledExtensions();
            $enabledProjects = self::enabledProjects($present);
            $installed = $context->drush->installedExtensionNames() ?? array_keys($present);
            $enabledExtensions = array_values(array_map('strval', $installed));
            $missingExtensions = array_values(array_diff($enabledExtensions, array_map('strval', array_keys($present))));
            foreach ($missingExtensions as $extension) {
                $replacement = RemovedCoreExtensions::replacement($extension);
                $this->warnings[] = $replacement !== null
                    ? "{$extension} is installed but its code is missing - Drupal {$replacement['major']} moved it to {$replacement['package']}."
                    : "{$extension} is installed but its code is missing from the codebase.";
            }
        }

        $progress('Working out safest upgrade targets');
        $core = self::buildCore($coreVersion, array_values($coreAdvisories), $histories['drupal'] ?? null);

        $packages = [];
        $candidates = array_unique(array_merge(array_keys($lock), array_keys($advisories), array_keys($outdated)));
        sort($candidates);
        foreach ($candidates as $package) {
            if (ComposerProject::isCorePackage($package)) {
                continue;
            }
            $status = $this->buildPackage(
                $package,
                $lock[$package] ?? null,
                $advisories[$package] ?? [],
                $outdated[$package] ?? null,
                $outdatedMinor[$package] ?? null,
                $histories[DrupalVersion::projectName($package) ?? ''] ?? null,
                $enabledProjects,
                $abandoned,
            );
            if ($status !== null) {
                $packages[] = $status;
            }
        }

        return new ScanResult(
            $context->site->name,
            $context->root(),
            date(DATE_ATOM),
            $core,
            $packages,
            self::customModules($context->root()),
            $environment,
            $this->warnings,
            $enabledExtensions,
            $missingExtensions,
        );
    }

    /**
     * Core targets: the lowest release clearing every advisory (same minor first), plus the newest
     * patch, minor and next-major releases from drupal.org.
     *
     * @param list<Advisory> $advisories
     */
    public static function buildCore(string $current, array $advisories, ?ReleaseHistory $history): CoreStatus
    {
        if ($current === '?' || !VersionResolver::isRelease($current)) {
            return new CoreStatus($current, null, null, null, null, $advisories, true, ['Installed core version could not be determined.']);
        }

        [$major, $minor] = VersionResolver::parts($current);
        $versions = $history?->versions() ?? [];
        $affected = array_values(array_filter(array_map(static fn (Advisory $a): ?string => $a->affectedVersions, $advisories)));

        $securityTarget = null;
        if ($advisories !== []) {
            $sameMinor = array_values(array_filter($versions, static fn (string $v): bool => VersionResolver::sameMinor($v, $current)));
            $sameMajor = array_values(array_filter($versions, static fn (string $v): bool => VersionResolver::sameMajor($v, $current)));
            $securityTarget = VersionResolver::firstUnaffectedRelease($current, $affected, $sameMinor)
                ?? VersionResolver::firstUnaffectedRelease($current, $affected, $sameMajor)
                ?? VersionResolver::firstUnaffectedRelease($current, $affected, $versions)
                ?? VersionResolver::minimumSecureVersion($current, $affected);
        }

        $newer = static fn (?string $v): ?string => $v !== null && VersionResolver::greaterThan($v, $current) ? $v : null;
        $latestPatch = $newer(VersionResolver::latestStable($versions, $major, $minor));
        $latestMinor = $newer(VersionResolver::latestStable($versions, $major));
        $nextMajor = $newer(VersionResolver::latestStable($versions, $major + 1));

        $supported = $history === null || $history->isBranchSupported($current);
        $notes = [];
        if (!$supported) {
            $branches = implode(', ', array_map(static fn (string $b): string => rtrim($b, '.') . '.x', $history->supportedBranches));
            $notes[] = "Drupal {$major}.{$minor}.x no longer receives security fixes. Supported branches: {$branches}.";
        }
        if ($major <= 9) {
            $notes[] = "Drupal {$major} is end-of-life. Plan an upgrade to Drupal 10 or 11.";
        }

        return new CoreStatus($current, $securityTarget, $latestPatch, $latestMinor, $nextMajor, $advisories, $supported, $notes);
    }

    /**
     * @param array{name: string, version: string, type: string, dev: bool, abandoned: bool|string}|null $lock
     * @param list<Advisory> $advisories
     * @param array{version: string, latest: string, status: string}|null $outdated
     * @param array{version: string, latest: string, status: string}|null $outdatedMinor
     * @param list<string>|null $enabledProjects
     * @param array<string, string|null> $abandonedMap
     */
    private function buildPackage(
        string $package,
        ?array $lock,
        array $advisories,
        ?array $outdated,
        ?array $outdatedMinor,
        ?ReleaseHistory $history,
        ?array $enabledProjects,
        array $abandonedMap,
    ): ?PackageStatus {
        $project = $this->context->project;
        $current = $lock['version'] ?? $outdated['version'] ?? null;
        if ($current === null || $current === '') {
            return null;
        }
        $type = $lock['type'] ?? (str_starts_with($package, 'drupal/') ? 'drupal-module' : 'library');
        $direct = $project->isDirect($package);
        $isDrupal = str_starts_with($package, 'drupal/') && str_starts_with($type, 'drupal-');
        $isAbandoned = array_key_exists($package, $abandonedMap) || ($lock !== null && $lock['abandoned'] !== false);

        if (!$isDrupal && $advisories === [] && $outdated === null && !$isAbandoned) {
            return null;
        }

        $securityTarget = null;
        if ($advisories !== []) {
            $affected = array_values(array_filter(array_map(static fn (Advisory $a): ?string => $a->affectedVersions, $advisories)));
            $releases = $this->context->composer->releases($package);
            if ($releases === [] && $history !== null) {
                $releases = $history->versions();
            }
            $securityTarget = VersionResolver::firstUnaffectedRelease($current, $affected, $releases)
                ?? VersionResolver::minimumSecureVersion($current, $affected);
        }

        $latestMinor = null;
        $latestMajor = null;
        $candidates = array_filter([$outdatedMinor['latest'] ?? null, $outdated['latest'] ?? null], static fn (?string $v): bool => $v !== null && $v !== '' && VersionResolver::isRelease($v));
        foreach ($candidates as $candidate) {
            if (!VersionResolver::greaterThan($candidate, $current)) {
                continue;
            }
            if (VersionResolver::sameMajor($candidate, $current)) {
                if ($latestMinor === null || VersionResolver::greaterThan($candidate, $latestMinor)) {
                    $latestMinor = $candidate;
                }
            } elseif ($latestMajor === null || VersionResolver::greaterThan($candidate, $latestMajor)) {
                $latestMajor = $candidate;
            }
        }

        $enabled = null;
        if ($enabledProjects !== null && in_array($type, ['drupal-module', 'drupal-theme', 'drupal-custom-module'], true)) {
            $enabled = in_array(DrupalVersion::projectName($package), $enabledProjects, true);
        }

        $replacement = $abandonedMap[$package] ?? (is_string($lock['abandoned'] ?? null) ? $lock['abandoned'] : null);

        return new PackageStatus(
            $package,
            $type,
            $direct,
            $lock['dev'] ?? $project->isDevRequirement($package),
            $current,
            $securityTarget,
            $latestMinor,
            $latestMajor,
            $advisories,
            $history?->status,
            $history === null || !VersionResolver::isRelease($current) || $history->isBranchSupported($current),
            $enabled,
            $isAbandoned,
            $replacement,
        );
    }

    /**
     * drupal.org projects with at least one enabled extension, derived from extension paths.
     *
     * @param array<string, array<string, mixed>> $extensions
     * @return list<string>
     */
    public static function enabledProjects(array $extensions): array
    {
        $projects = [];
        foreach ($extensions as $machineName => $info) {
            $path = (string) ($info['path'] ?? '');
            if (preg_match('#(?:modules|themes|profiles)/contrib/([^/]+)#', $path, $m) === 1) {
                $projects[] = $m[1];
            } else {
                $projects[] = (string) $machineName;
            }
        }
        return array_values(array_unique($projects));
    }

    /**
     * Custom modules and themes (rector and upgrade_status targets).
     *
     * @return array<string, string> name => path
     */
    public static function customModules(string $root): array
    {
        $docroot = DrupalProjectDetector::docroot($root);
        $dirs = array_unique([
            $docroot . '/modules/custom',
            $docroot . '/themes/custom',
            $docroot . '/profiles/custom',
            $root . '/modules/custom',
        ]);
        $found = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                $path = $dir . '/' . $entry;
                if ($entry[0] === '.' || !is_dir($path)) {
                    continue;
                }
                if (is_file("{$path}/{$entry}.info.yml") || is_file("{$path}/{$entry}.module")) {
                    $found[$entry] = $path;
                }
            }
        }
        ksort($found);
        return $found;
    }

    /**
     * @param callable(string): void $progress
     * @return array<string, string|bool|null>
     */
    private function environmentInfo(callable $progress): array
    {
        $context = $this->context;
        $progress('Checking PHP, Git and Drush');

        $php = $context->environment->php(['-r', 'echo PHP_VERSION;']);
        $phpResult = $context->runner->run($php->command, $context->root(), $php->env, null, 120);

        $branch = $context->runner->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $context->root(), [], null, 30);
        $gitStatus = $branch->successful()
            ? $context->runner->run(['git', 'status', '--porcelain', '--untracked-files=no'], $context->root(), [], null, 60)
            : null;

        $bootstrap = null;
        $drupalStatus = null;
        if ($context->drush !== null) {
            $drupalStatus = $context->drush->status();
            $bootstrap = ($drupalStatus['bootstrap'] ?? '') === 'Successful';
            if (!$bootstrap) {
                $this->warnings[] = 'Drush could not bootstrap Drupal (is the database running?). Database updates and verification will be skipped.';
            }
        } else {
            $this->warnings[] = 'Drush is not installed in this project. Add it with `composer require drush/drush` for database updates and verification.';
        }

        return [
            'environment' => $context->environment->label(),
            'php' => $phpResult->successful() ? trim($phpResult->output) : null,
            'composer' => $context->composer->version(),
            'drush' => $context->drush?->info->version,
            'bootstrap' => $bootstrap,
            'database' => is_array($drupalStatus) && isset($drupalStatus['db-driver'])
                ? trim($drupalStatus['db-driver'] . ':' . ($drupalStatus['db-name'] ?? ''), ':')
                : null,
            'git_branch' => $branch->successful() ? trim($branch->output) : null,
            'git_clean' => $gitStatus === null ? null : trim($gitStatus->output) === '',
        ];
    }
}
