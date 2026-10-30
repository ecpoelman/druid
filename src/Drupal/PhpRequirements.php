<?php

declare(strict_types=1);

namespace Druid\Drupal;

use Druid\Composer\ComposerProject;
use Symfony\Component\Yaml\Yaml;

/**
 * Minimum PHP versions per Drupal core major, checked against the PHP running the site, Composer's
 * platform config and hosting config (Pantheon, DDEV).
 */
final class PhpRequirements
{
    /** Drupal major => minimum PHP version. */
    public const MINIMUM = [
        8 => '7.0.8',
        9 => '7.3.0',
        10 => '8.1.0',
        11 => '8.3.0',
        12 => '8.3.0',
    ];

    public static function minimumFor(int $drupalMajor): string
    {
        if (isset(self::MINIMUM[$drupalMajor])) {
            return self::MINIMUM[$drupalMajor];
        }
        $known = array_keys(self::MINIMUM);
        return $drupalMajor > max($known) ? self::MINIMUM[max($known)] : self::MINIMUM[min($known)];
    }

    /**
     * Problems that will stop Drupal `$drupalMajor` from installing, as human-readable notes.
     *
     * @return list<string>
     */
    public static function problems(int $drupalMajor, ?string $phpVersion, ?ComposerProject $project = null): array
    {
        $minimum = self::minimumFor($drupalMajor);
        $short = self::short($minimum);
        $problems = [];

        if ($phpVersion !== null && self::below($phpVersion, $minimum)) {
            $problems[] = "Drupal {$drupalMajor} needs PHP {$short}+, this site runs PHP {$phpVersion}. Switch PHP first (Herd: `herd isolate {$short}`; DDEV: `ddev config --php-version={$short}`).";
        }
        if ($project === null) {
            return $problems;
        }

        $platform = self::platformPhp($project);
        if ($platform !== null && self::below($platform, $minimum)) {
            $problems[] = "composer.json pins config.platform.php to {$platform}, so Composer resolves packages for PHP {$platform} whatever PHP runs it. Drupal {$drupalMajor} needs {$short}+: run `composer config platform.php {$short}`.";
        }
        foreach (self::hostingPhp($project->root) as $file => $version) {
            if (self::below($version, $minimum)) {
                $problems[] = "{$file} sets php_version to {$version}; set it to {$short} so the server runs Drupal {$drupalMajor}.";
            }
        }
        return $problems;
    }

    /**
     * Commands that raise the PHP pins found in the project to what Drupal `$drupalMajor` needs.
     *
     * @return list<string>
     */
    public static function fixCommands(int $drupalMajor, ComposerProject $project): array
    {
        $minimum = self::minimumFor($drupalMajor);
        $short = self::short($minimum);
        $commands = [];
        $platform = self::platformPhp($project);
        if ($platform !== null && self::below($platform, $minimum)) {
            $commands[] = "composer config platform.php {$short}";
        }
        foreach (self::hostingPhp($project->root) as $file => $version) {
            if (self::below($version, $minimum)) {
                $commands[] = $file === '.ddev/config.yaml'
                    ? "ddev config --php-version={$short} && ddev restart"
                    : "edit {$file}: php_version: {$short}";
            }
        }
        return $commands;
    }

    /**
     * Hosting files whose php_version is below what Drupal `$drupalMajor` needs.
     *
     * @return array<string, string> file => version
     */
    public static function outdatedHostingPhp(int $drupalMajor, string $root): array
    {
        $minimum = self::minimumFor($drupalMajor);
        return array_filter(self::hostingPhp($root), static fn (string $version): bool => self::below($version, $minimum));
    }

    public static function platformPhpTooLow(int $drupalMajor, ComposerProject $project): bool
    {
        $platform = self::platformPhp($project);
        return $platform !== null && self::below($platform, self::minimumFor($drupalMajor));
    }

    /**
     * Sets php_version in a hosting file (pantheon.yml, .ddev/config.yaml), leaving the rest untouched.
     */
    public static function setHostingPhp(string $root, string $file, string $version): bool
    {
        $path = $root . '/' . $file;
        $yaml = @file_get_contents($path);
        if ($yaml === false) {
            return false;
        }
        $updated = preg_replace('/^php_version:[^\S\n]*[^\s#]*/m', 'php_version: ' . $version, $yaml, 1, $count);
        return $count === 1 && is_string($updated) && file_put_contents($path, $updated) !== false;
    }

    public static function shortMinimum(int $drupalMajor): string
    {
        return self::short(self::minimumFor($drupalMajor));
    }

    public static function platformPhp(ComposerProject $project): ?string
    {
        $platform = $project->json()['config']['platform']['php'] ?? null;
        return is_string($platform) || is_float($platform) ? (string) $platform : null;
    }

    /**
     * PHP versions declared by hosting config files in the project.
     *
     * @return array<string, string> file => version
     */
    public static function hostingPhp(string $root): array
    {
        $versions = [];
        // pantheon.yml overrides pantheon.upstream.yml.
        foreach (['pantheon.yml', 'pantheon.upstream.yml', '.ddev/config.yaml'] as $file) {
            $path = $root . '/' . $file;
            if (!is_file($path) || ($file === 'pantheon.upstream.yml' && isset($versions['pantheon.yml']))) {
                continue;
            }
            try {
                $data = Yaml::parseFile($path);
            } catch (\Throwable) {
                continue;
            }
            $version = is_array($data) ? ($data['php_version'] ?? null) : null;
            if (is_string($version) || is_float($version) || is_int($version)) {
                $versions[$file] = self::formatYamlVersion($version);
            }
        }
        return $versions;
    }

    /**
     * True when `$version` (e.g. "8.2" or "8.2.29") is below `$minimum` (e.g. "8.3.0").
     */
    public static function below(string $version, string $minimum): bool
    {
        if (preg_match('/^\d+(\.\d+){0,2}/', trim($version), $m) !== 1) {
            return false;
        }
        return version_compare(self::pad($m[0]), self::pad($minimum), '<');
    }

    private static function pad(string $version): string
    {
        $parts = array_slice(array_pad(explode('.', $version), 3, '0'), 0, 3);
        return implode('.', $parts);
    }

    private static function short(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    private static function formatYamlVersion(string|float|int $version): string
    {
        // YAML reads `php_version: 8.2` as a float; 8.1 and 8.10 can't be told apart, so keep the float text.
        return is_string($version) ? trim($version) : (string) $version;
    }
}
