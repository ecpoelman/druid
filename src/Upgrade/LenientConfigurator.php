<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Composer\ComposerProject;

/**
 * Composer commands that install mglaman/composer-drupal-lenient and extend its allow-list,
 * so contrib without a declared core_version_requirement for the new major can be installed.
 */
final class LenientConfigurator
{
    public const PLUGIN = 'mglaman/composer-drupal-lenient';

    /**
     * @param list<string> $packages
     * @return list<Attempt>
     */
    public static function setupAttempts(ComposerProject $project, array $packages): array
    {
        $attempts = [];
        if (!$project->hasPackage(self::PLUGIN)) {
            if (!$project->pluginAllowed(self::PLUGIN)) {
                $attempts[] = new Attempt('lenient', 'Allow the lenient plugin', ['config', '--no-plugins', 'allow-plugins.' . self::PLUGIN, 'true']);
            }
            $attempts[] = new Attempt('lenient', 'Install the lenient plugin', ['require', self::PLUGIN]);
        }

        $list = array_values(array_unique(array_merge($project->lenientAllowList(), $packages)));
        sort($list);
        $attempts[] = new Attempt('lenient', 'Add to the lenient allow-list', ['config', '--json', 'extra.drupal-lenient.allowed-list', (string) json_encode($list, JSON_UNESCAPED_SLASHES)]);
        return $attempts;
    }
}
