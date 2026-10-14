<?php

declare(strict_types=1);

namespace Druid\Drupal;

/**
 * Core modules and themes that a Drupal major removed, and the contrib project that replaces each.
 * A site with one of them enabled needs the contrib project installed together with the new core.
 */
final class RemovedCoreExtensions
{
    /** Drupal major that removed them => extension machine name => contrib package. */
    public const REMOVED = [
        10 => [
            'aggregator' => 'drupal/aggregator',
            'bartik' => 'drupal/bartik',
            'ckeditor' => 'drupal/ckeditor',
            'classy' => 'drupal/classy',
            'color' => 'drupal/color',
            'hal' => 'drupal/hal',
            'quickedit' => 'drupal/quickedit',
            'rdf' => 'drupal/rdf',
            'seven' => 'drupal/seven',
            'stable' => 'drupal/stable',
        ],
        11 => [
            'action' => 'drupal/action',
            'book' => 'drupal/book',
            'forum' => 'drupal/forum',
            'statistics' => 'drupal/statistics',
            'tour' => 'drupal/tour',
            'tracker' => 'drupal/tracker',
        ],
    ];

    /**
     * Enabled extensions removed by a major in (`$fromMajor`, `$toMajor`], as extension => [package, major].
     *
     * @param list<string> $enabled
     * @return array<string, array{package: string, major: int}>
     */
    public static function affecting(array $enabled, int $fromMajor, int $toMajor): array
    {
        $found = [];
        foreach (self::REMOVED as $major => $extensions) {
            if ($major <= $fromMajor || $major > $toMajor) {
                continue;
            }
            foreach ($extensions as $extension => $package) {
                if (in_array($extension, $enabled, true)) {
                    $found[$extension] = ['package' => $package, 'major' => $major];
                }
            }
        }
        return $found;
    }

    /**
     * The contrib replacement for `$extension` when any major removed it from core.
     *
     * @return array{package: string, major: int}|null
     */
    public static function replacement(string $extension): ?array
    {
        foreach (self::REMOVED as $major => $extensions) {
            if (isset($extensions[$extension])) {
                return ['package' => $extensions[$extension], 'major' => $major];
            }
        }
        return null;
    }
}
