<?php

declare(strict_types=1);

namespace Druid\Report;

use Druid\Scan\PackageStatus;
use Druid\Scan\ScanResult;
use Druid\Security\Advisory;

/**
 * Flattens scan results into report rows: one per package (and core) that needs attention.
 */
final class ReportRows
{
    public const HEADERS = ['site', 'package', 'category', 'severity', 'installed', 'recommended', 'latest', 'advisories', 'note'];

    /**
     * @param list<ScanResult> $results
     * @return list<array<string, string>>
     */
    public static function build(array $results, bool $includeOk = false): array
    {
        $rows = [];
        foreach ($results as $scan) {
            $core = $scan->core;
            if ($core->securityTarget !== null || $core->latestPatch !== null || !$core->supported || $includeOk) {
                $rows[] = [
                    'site' => $scan->site,
                    'package' => 'drupal/core',
                    'category' => $core->securityTarget !== null ? 'security' : (!$core->supported ? 'unsupported' : ($core->latestPatch !== null ? 'minor' : 'ok')),
                    'severity' => $core->advisories !== [] ? $core->severity()->value : '',
                    'installed' => $core->current,
                    'recommended' => (string) ($core->securityTarget ?? $core->latestPatch ?? ''),
                    'latest' => (string) ($core->latestMinor ?? $core->latestPatch ?? ''),
                    'advisories' => self::ids($core->advisories),
                    'note' => implode(' ', $core->notes),
                ];
            }
            foreach ($scan->packages as $package) {
                $category = $package->category();
                if ($category === PackageStatus::OK && !$includeOk) {
                    continue;
                }
                $rows[] = [
                    'site' => $scan->site,
                    'package' => $package->package,
                    'category' => $category,
                    'severity' => $package->advisories !== [] ? $package->severity()->value : '',
                    'installed' => $package->current,
                    'recommended' => (string) ($package->securityTarget ?? $package->latestMinor ?? $package->latestMajor ?? ''),
                    'latest' => (string) ($package->latestMajor ?? $package->latestMinor ?? ''),
                    'advisories' => self::ids($package->advisories),
                    'note' => self::note($package),
                ];
            }
        }
        return $rows;
    }

    /**
     * @param list<Advisory> $advisories
     */
    private static function ids(array $advisories): string
    {
        return implode(' ', array_unique(array_map(static fn (Advisory $a): string => $a->cve ?? $a->id, $advisories)));
    }

    private static function note(PackageStatus $package): string
    {
        $notes = [];
        if ($package->abandoned) {
            $notes[] = 'abandoned' . ($package->replacement !== null ? ", use {$package->replacement}" : '');
        }
        if ($package->projectStatus !== null && $package->projectStatus !== 'published') {
            $notes[] = "project {$package->projectStatus}";
        }
        if (!$package->branchSupported) {
            $notes[] = 'branch unsupported';
        }
        if ($package->enabled === false) {
            $notes[] = 'not enabled';
        }
        if (!$package->direct) {
            $notes[] = 'dependency';
        }
        return implode('; ', $notes);
    }
}
