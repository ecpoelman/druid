<?php

declare(strict_types=1);

namespace Druid\Drupal;

/**
 * Conversions between drupal.org release names and Composer versions.
 */
final class DrupalVersion
{
    /**
     * `8.x-1.15` becomes `1.15.0`, `8.x-1.0-beta3` becomes `1.0.0-beta3`; semantic versions pass through.
     */
    public static function toComposer(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^\d+\.x-(.+)$/', $version, $m) === 1) {
            $version = $m[1];
            if (preg_match('/^(\d+)\.(\d+)(-.+)?$/', $version, $p) === 1) {
                $version = "{$p[1]}.{$p[2]}.0" . ($p[3] ?? '');
            }
        }
        return $version;
    }

    /**
     * Release branch prefixes in Composer form, e.g. `8.x-1.` becomes `1.`.
     */
    public static function branchToComposer(string $branch): string
    {
        $branch = trim($branch);
        return preg_match('/^\d+\.x-(.+)$/', $branch, $m) === 1 ? $m[1] : $branch;
    }

    /**
     * drupal.org project short name for a Composer package (`drupal/core-recommended` is `drupal`).
     */
    public static function projectName(string $package): ?string
    {
        if (!str_starts_with($package, 'drupal/')) {
            return null;
        }
        if (str_starts_with($package, 'drupal/core')) {
            return 'drupal';
        }
        return substr($package, strlen('drupal/'));
    }
}
