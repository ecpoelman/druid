<?php

declare(strict_types=1);

namespace Druid\Composer;

use Druid\Security\VersionResolver;

/**
 * Parses and classifies Composer resolver "Problem N" output, and extracts blocking packages.
 */
final class ProblemParser
{
    private const PACKAGE_PATTERN = '[a-z0-9](?:[a-z0-9_.-]*)\/[a-z0-9](?:[a-z0-9_.-]*)';

    /**
     * @return list<ComposerProblem>
     */
    public static function parse(string $output): array
    {
        $problems = [];
        $current = [];

        foreach (explode("\n", $output) as $line) {
            $stripped = trim(preg_replace('/^\[stderr\]\s*/', '', $line) ?? $line);
            if (preg_match('/^Problem \d+$/', $stripped) === 1) {
                if ($current !== []) {
                    $problems[] = self::classify($current);
                }
                $current = [$stripped];
            } elseif ($current !== [] && str_starts_with($stripped, '- ')) {
                $current[] = $stripped;
            } elseif ($current !== [] && ($stripped === '' || str_starts_with($stripped, 'Use the option') || str_starts_with($stripped, 'Installation failed'))) {
                $problems[] = self::classify($current);
                $current = [];
            }
        }
        if ($current !== []) {
            $problems[] = self::classify($current);
        }
        return $problems;
    }

    /**
     * Collapses repetitive "locked contrib" noise into a single summary entry.
     *
     * @param list<ComposerProblem> $problems
     * @return list<ComposerProblem>
     */
    public static function distill(array $problems): array
    {
        $primary = [];
        $lockedCascade = 0;
        foreach ($problems as $problem) {
            if ($problem->kind === 'locked_package' && $problem->resolvesWithCore) {
                $lockedCascade++;
                continue;
            }
            if ($problem->kind === 'audit_blocked' && $problem->resolvesWithCore && $lockedCascade > 0) {
                continue;
            }
            $primary[] = $problem;
        }
        if ($lockedCascade > 0) {
            $primary[] = new ComposerProblem(
                0,
                'locked_cascade',
                null,
                "{$lockedCascade} contrib package(s) locked at current versions",
                'Composer lists these because core is still on a vulnerable or older release. They are not separate failures - update core first.',
                null,
                true,
            );
        }
        return $primary;
    }

    /**
     * Packages that block an update, gathered from problem blocks and `composer why-not` output.
     *
     * @param list<string> $ignore packages to leave out (the root project, the package being updated)
     * @return list<string>
     */
    public static function blockers(string $output, array $ignore = []): array
    {
        $found = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim(preg_replace('/^\[stderr\]\s*/', '', $line) ?? $line);
            $line = ltrim($line, '- ');

            // why-not: "drupal/foo  1.2.3  requires  drupal/core (^10)"
            // problems: "drupal/foo 1.2.3 requires drupal/core ^10 -> found ..."
            if (preg_match('/^(' . self::PACKAGE_PATTERN . ')\s+v?[\w.\-+]+\s+(requires|conflicts)/i', $line, $m) === 1) {
                $found[] = strtolower($m[1]);
            }
            if (preg_match('/^(' . self::PACKAGE_PATTERN . ') is locked to version/i', $line, $m) === 1) {
                $found[] = strtolower($m[1]);
            }
        }

        $ignore = array_map('strtolower', $ignore);
        $ignore[] = '__root__';
        return array_values(array_unique(array_filter(
            $found,
            static fn (string $package): bool => !in_array($package, $ignore, true),
        )));
    }

    /**
     * Packages Composer found but would not load because another locked package's requirement rules them
     * out, with the lowest version it needed (e.g. "symfony/console" => "7.4.13").
     *
     * @return array<string, string>
     */
    public static function notLoaded(string $output): array
    {
        $pattern = '/found (' . self::PACKAGE_PATTERN . ')\[([^\]]+)\] but (?:these were not loaded|it conflicts with another require|these conflict with another require)/i';
        preg_match_all($pattern, $output, $matches, PREG_SET_ORDER);
        $found = [];
        foreach ($matches as $match) {
            $versions = array_values(array_filter(
                array_map(static fn (string $v): string => ltrim(trim($v), 'vV'), explode(',', $match[2])),
                static fn (string $v): bool => VersionResolver::isRelease($v),
            ));
            $stable = array_values(array_filter($versions, static fn (string $v): bool => VersionResolver::isStable($v)));
            $version = $stable[0] ?? $versions[0] ?? null;
            if ($version !== null) {
                $found[strtolower($match[1])] ??= $version;
            }
        }
        return $found;
    }

    /**
     * PKSA advisory identifiers mentioned in output.
     *
     * @return list<string>
     */
    public static function advisoryIds(string $output): array
    {
        preg_match_all('/PKSA-[A-Za-z0-9-]+/', $output, $matches);
        return array_values(array_unique($matches[0]));
    }

    /**
     * @param list<string> $lines
     */
    private static function classify(array $lines): ComposerProblem
    {
        $joined = implode(' ', $lines);
        $number = preg_match('/^Problem (\d+)/', $lines[0], $m) === 1 ? (int) $m[1] : 0;

        foreach ($lines as $line) {
            if (str_contains($line, 'exact version match') && str_contains($line, 'drupal/core')) {
                $pinned = preg_match('/drupal\/core\s+([^\s(,]+)/', $line, $vm) === 1 ? $vm[1] : 'an old version';
                return new ComposerProblem($number, 'exact_core_pin', 'drupal/core', "composer.json pins drupal/core to {$pinned} - this blocks moving to the new release", trim($line), null, true);
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/(' . self::PACKAGE_PATTERN . ') is locked to version/i', $line, $lm) === 1) {
                $package = $lm[1];
                $isCore = ComposerProject::isCorePackage($package);
                return new ComposerProblem(
                    $number,
                    $isCore ? 'locked_core' : 'locked_package',
                    $package,
                    $isCore ? "{$package} is locked in composer.lock" : "{$package} is locked - not updated unless explicitly requested",
                    $joined,
                    $isCore ? null : "composer update {$package} --with-all-dependencies",
                    !$isCore,
                );
            }
        }

        if (preg_match('/requires php ([^\s]+)/i', $joined, $pm) === 1 && str_contains($joined, 'your php version')) {
            return new ComposerProblem($number, 'php_requirement', null, "A package requires PHP {$pm[1]}, which does not match the PHP running Composer", $joined);
        }

        if (str_contains($joined, 'affected by security advisories')) {
            $package = null;
            foreach ($lines as $line) {
                if (str_contains($line, 'requires drupal/core')) {
                    $package = 'drupal/core';
                    break;
                }
                if (preg_match('/^- (' . self::PACKAGE_PATTERN . ') .* requires /i', $line, $rm) === 1) {
                    $package = $rm[1];
                    break;
                }
            }
            $detail = $joined;
            foreach ($lines as $line) {
                if (str_contains($line, 'affected by security advisories')) {
                    $detail = trim($line);
                    break;
                }
            }
            return new ComposerProblem($number, 'audit_blocked', $package, 'Composer audit blocked package versions while resolving dependencies', $detail, null, true);
        }

        return new ComposerProblem($number, 'other', null, trim($lines[1] ?? 'Dependency conflict', '- '), $joined);
    }
}
