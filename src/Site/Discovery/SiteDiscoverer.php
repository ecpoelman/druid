<?php

declare(strict_types=1);

namespace Druid\Site\Discovery;

use Druid\Config\Config;
use Druid\Environment\ProcessRunner;
use Druid\Site\Site;
use Druid\Util\Path;

/**
 * Runs every enabled discovery source and de-duplicates by Composer root.
 */
final class SiteDiscoverer
{
    public function __construct(
        private readonly Config $config,
        private readonly ProcessRunner $runner,
    ) {
    }

    /**
     * @return list<DiscoverySource>
     */
    public function sources(): array
    {
        $sources = [];
        if ($this->config->bool('discovery.herd', true)) {
            $sources[] = new HerdDiscovery($this->runner);
        }
        if ($this->config->bool('discovery.ddev', true)) {
            $sources[] = new DdevDiscovery($this->runner);
        }
        foreach ($this->config->list('discovery.paths') as $path) {
            $sources[] = new SitesDirectoryDiscovery(Path::expand($path));
        }
        return $sources;
    }

    /**
     * @return list<Site> sorted by name
     */
    public function discover(): array
    {
        $byRoot = [];
        foreach ($this->sources() as $source) {
            foreach ($source->discover() as $site) {
                $key = Path::normalize($site->root);
                $byRoot[$key] ??= $site;
            }
        }

        if ($this->config->bool('discovery.drush_aliases', true)) {
            foreach ((new DrushAliasDiscovery(array_keys($byRoot)))->discover() as $site) {
                $key = Path::normalize($site->root);
                $byRoot[$key] ??= $site;
            }
        }

        $sites = array_values($byRoot);
        usort($sites, static fn (Site $a, Site $b): int => strnatcasecmp($a->name, $b->name));
        return $sites;
    }
}
