<?php

declare(strict_types=1);

namespace Druid\Security;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Version maths on top of composer/semver: advisory range matching, safest-fix selection
 * and constraint checks.
 */
final class VersionResolver
{
    private static ?VersionParser $parser = null;

    // MARK: - Basics

    public static function normalize(string $version): ?string
    {
        try {
            return self::parser()->normalize(ltrim(trim($version), 'vV'));
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * A tagged release (not a dev branch) that Composer can parse.
     */
    public static function isRelease(string $version): bool
    {
        return !str_contains(strtolower($version), 'dev') && self::normalize($version) !== null;
    }

    public static function isStable(string $version): bool
    {
        return self::isRelease($version) && VersionParser::parseStability($version) === 'stable';
    }

    /**
     * -1, 0 or 1, comparing normalized versions (so 1.0.0-beta3 < 1.0.0 < 1.0.1).
     */
    public static function compare(string $a, string $b): int
    {
        $na = self::normalize($a) ?? ltrim($a, 'vV');
        $nb = self::normalize($b) ?? ltrim($b, 'vV');
        return version_compare($na, $nb);
    }

    public static function greaterThan(string $a, string $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    /**
     * @param list<string> $versions
     * @return list<string>
     */
    public static function sort(array $versions, bool $descending = false): array
    {
        usort($versions, static fn (string $a, string $b): int => $descending ? self::compare($b, $a) : self::compare($a, $b));
        return array_values($versions);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function parts(string $version): array
    {
        $numeric = preg_split('/[-+]/', ltrim(trim($version), 'vV'))[0] ?? '';
        $pieces = array_map('intval', array_slice(explode('.', $numeric), 0, 3));
        return [$pieces[0] ?? 0, $pieces[1] ?? 0, $pieces[2] ?? 0];
    }

    public static function major(string $version): int
    {
        return self::parts($version)[0];
    }

    public static function sameMajor(string $a, string $b): bool
    {
        return self::major($a) === self::major($b);
    }

    public static function sameMinor(string $a, string $b): bool
    {
        $pa = self::parts($a);
        $pb = self::parts($b);
        return $pa[0] === $pb[0] && $pa[1] === $pb[1];
    }

    // MARK: - Constraints and advisories

    /**
     * True when a version satisfies a Composer constraint. Unparseable input returns false
     * so callers fall back to the safer path (e.g. `composer require`).
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies(ltrim(trim($version), 'vV'), $constraint);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * True when a root composer.json constraint (e.g. `^6.2`, `~2.0.9 || ^3`) allows `$version`.
     */
    public static function constraintAllows(string $constraint, string $version): bool
    {
        return self::satisfies($version, $constraint);
    }

    /**
     * True when `$version` falls inside an advisory's affected range, e.g. `>=10.6.0 <10.6.11 || <10.5.12`.
     */
    public static function isAffected(string $version, string $affected): bool
    {
        if (trim($affected) === '') {
            return false;
        }
        return self::satisfies($version, $affected);
    }

    /**
     * @param list<string> $affected
     */
    public static function isAffectedByAny(string $version, array $affected): bool
    {
        foreach ($affected as $range) {
            if (self::isAffected($version, $range)) {
                return true;
            }
        }
        return false;
    }

    // MARK: - Choosing targets

    /**
     * Lowest published release above `$current` that no advisory range covers.
     * Prefers stable releases; falls back to pre-releases only when no stable fix exists.
     *
     * @param list<string> $affected
     * @param list<string> $releases
     */
    public static function firstUnaffectedRelease(string $current, array $affected, array $releases): ?string
    {
        $candidates = array_values(array_filter(
            $releases,
            static fn (string $v): bool => self::isRelease($v)
                && self::greaterThan($v, $current)
                && !self::isAffectedByAny($v, $affected),
        ));
        if ($candidates === []) {
            return null;
        }
        $candidates = self::sort($candidates);
        foreach ($candidates as $candidate) {
            if (self::isStable($candidate)) {
                return ltrim($candidate, 'vV');
            }
        }
        return ltrim($candidates[0], 'vV');
    }

    /**
     * Newest stable release in the same major line as `$current` (falls back to the overall newest).
     *
     * @param list<string> $releases
     */
    public static function latestStableRelease(string $current, array $releases): ?string
    {
        return self::latestStable($releases, self::major($current))
            ?? self::latestStable($releases);
    }

    /**
     * Newest stable release, optionally limited to one major (and minor) line.
     *
     * @param list<string> $releases
     */
    public static function latestStable(array $releases, ?int $major = null, ?int $minor = null): ?string
    {
        $best = null;
        foreach ($releases as $release) {
            if (!self::isStable($release)) {
                continue;
            }
            [$ma, $mi] = self::parts($release);
            if (($major !== null && $ma !== $major) || ($minor !== null && $mi !== $minor)) {
                continue;
            }
            if ($best === null || self::greaterThan($release, $best)) {
                $best = $release;
            }
        }
        return $best === null ? null : ltrim($best, 'vV');
    }

    /**
     * Walks up through affected ranges until reaching a version none of them cover.
     * Used when the release list is unavailable; can't resolve inclusive (`<=`) upper bounds.
     *
     * @param list<string> $affected
     */
    public static function minimumSecureVersion(string $current, array $affected): ?string
    {
        $branches = [];
        foreach ($affected as $range) {
            foreach (preg_split('/\|+/', $range) ?: [] as $branch) {
                if (trim($branch) !== '') {
                    $branches[] = trim($branch);
                }
            }
        }

        $start = ltrim(trim($current), 'vV');
        $candidate = $start;
        for ($i = 0; $i < 32; $i++) {
            $containing = null;
            foreach ($branches as $branch) {
                if (self::satisfies($candidate, $branch)) {
                    $containing = $branch;
                    break;
                }
            }
            if ($containing === null) {
                return $candidate !== $start ? $candidate : null;
            }
            $upper = self::exclusiveUpperBound($containing);
            if ($upper === null) {
                return null;
            }
            $candidate = $upper;
        }
        return null;
    }

    /**
     * The `<X` bound of one range branch, e.g. `10.6.11` for `>=10.6.0 <10.6.11`.
     */
    public static function exclusiveUpperBound(string $branch): ?string
    {
        foreach (self::branchTokens($branch) as $token) {
            if (str_starts_with($token, '<') && !str_starts_with($token, '<=')) {
                return ltrim(substr($token, 1), 'vV');
            }
        }
        return null;
    }

    /**
     * Splits a branch into operator-attached tokens (`< 2.1.5` becomes `<2.1.5`).
     *
     * @return list<string>
     */
    private static function branchTokens(string $branch): array
    {
        $tokens = [];
        $pending = '';
        foreach (preg_split('/[\s,]+/', trim($branch)) ?: [] as $piece) {
            if ($piece === '') {
                continue;
            }
            if (preg_match('/^[<>=^~!]+$/', $piece) === 1) {
                $pending = $piece;
                continue;
            }
            $tokens[] = $pending . $piece;
            $pending = '';
        }
        return $tokens;
    }

    private static function parser(): VersionParser
    {
        return self::$parser ??= new VersionParser();
    }
}
