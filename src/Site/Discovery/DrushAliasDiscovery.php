<?php

declare(strict_types=1);

namespace Druid\Site\Discovery;

use Druid\Site\DrupalProjectDetector;
use Druid\Site\Site;
use Druid\Util\Path;
use Symfony\Component\Yaml\Yaml;

/**
 * Local (host-less) Drush site aliases from ~/.drush/sites and project drush/sites/*.site.yml files.
 */
final class DrushAliasDiscovery implements DiscoverySource
{
    /**
     * @param list<string> $projectRoots additional projects whose drush/sites folder should be read
     */
    public function __construct(private readonly array $projectRoots = [])
    {
    }

    public function name(): string
    {
        return 'drush-aliases';
    }

    public function discover(): iterable
    {
        $dirs = [Path::home() . '/.drush/sites'];
        foreach ($this->projectRoots as $root) {
            $dirs[] = $root . '/drush/sites';
        }
        foreach (array_unique($dirs) as $dir) {
            foreach (glob($dir . '/*.site.yml') ?: [] as $file) {
                yield from self::parseAliasFile($file);
            }
        }
    }

    /**
     * @return list<Site>
     */
    public static function parseAliasFile(string $file): array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }
        $group = basename($file, '.site.yml');
        $sites = [];
        foreach ($data as $env => $alias) {
            if (!is_array($alias) || isset($alias['host']) || !isset($alias['root']) || !is_string($alias['root'])) {
                continue;
            }
            $root = DrupalProjectDetector::findComposerRoot(Path::expand($alias['root']));
            if ($root === null) {
                continue;
            }
            $name = count($data) === 1 || $env === 'local' ? $group : "{$group}.{$env}";
            $uri = isset($alias['uri']) && is_string($alias['uri']) ? $alias['uri'] : null;
            $sites[] = new Site($name, $root, $uri, 'drush-alias', $uri);
        }
        return $sites;
    }
}
