<?php

declare(strict_types=1);

namespace Druid\Drush;

use Druid\Composer\ComposerProject;
use Druid\Site\DrupalProjectDetector;

/**
 * Finds the project's own Drush (vendor/bin/drush), as Drush 9+ requires.
 */
final class DrushDetector
{
    public static function detect(string $root, ComposerProject $project): ?DrushInfo
    {
        $candidates = [
            DrupalProjectDetector::binDir($root) . '/drush',
            DrupalProjectDetector::vendorDir($root) . '/drush/drush/drush',
        ];
        foreach ($candidates as $binary) {
            if (is_file($binary)) {
                return new DrushInfo($binary, $project->lockedVersion('drush/drush'));
            }
        }
        if ($project->lockedVersion('drush/drush') !== null && is_file($root . '/.ddev/config.yaml')) {
            // Vendor lives only inside the container; `ddev drush` still works.
            return new DrushInfo('drush', $project->lockedVersion('drush/drush'));
        }
        return null;
    }
}
