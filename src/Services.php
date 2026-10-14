<?php

declare(strict_types=1);

namespace Druid;

use Druid\Composer\ComposerProject;
use Druid\Composer\ComposerRunner;
use Druid\Config\Config;
use Druid\Config\ConfigLoader;
use Druid\Drupal\ComposerMetadataClient;
use Druid\Drupal\ReleaseHistoryClient;
use Druid\Drush\DrushDetector;
use Druid\Drush\DrushRunner;
use Druid\Environment\EnvironmentDetector;
use Druid\Environment\ProcessRunner;
use Druid\Site\Discovery\SiteDiscoverer;
use Druid\Site\Site;
use Druid\Site\SiteLocator;
use Druid\Site\SiteRegistry;
use Druid\State\StateStore;
use Druid\Upgrade\SnapshotStore;
use Druid\Util\Path;

/**
 * Lazily-built shared services. Paths default to `~/.druid`, overridable with DRUID_HOME.
 */
final class Services
{
    private readonly string $home;
    private ?ProcessRunner $processRunner = null;
    private ?ConfigLoader $configLoader = null;
    private ?SiteRegistry $registry = null;
    private ?StateStore $state = null;
    private ?ReleaseHistoryClient $releaseHistory = null;
    private ?ComposerMetadataClient $composerMetadata = null;
    private ?SnapshotStore $snapshots = null;

    public function __construct(?string $home = null)
    {
        $envHome = getenv('DRUID_HOME');
        $this->home = rtrim($home ?? (is_string($envHome) && $envHome !== '' ? $envHome : Path::home() . '/.druid'), '/');
    }

    public function home(): string
    {
        return $this->home;
    }

    public function processRunner(): ProcessRunner
    {
        return $this->processRunner ??= new ProcessRunner();
    }

    public function configLoader(): ConfigLoader
    {
        return $this->configLoader ??= new ConfigLoader($this->home);
    }

    public function globalConfig(): Config
    {
        return $this->configLoader()->load();
    }

    public function registry(): SiteRegistry
    {
        return $this->registry ??= new SiteRegistry($this->home . '/sites.yml');
    }

    public function state(): StateStore
    {
        return $this->state ??= new StateStore($this->home . '/state');
    }

    public function snapshots(): SnapshotStore
    {
        return $this->snapshots ??= new SnapshotStore($this->home . '/snapshots');
    }

    public function releaseHistory(): ReleaseHistoryClient
    {
        if ($this->releaseHistory === null) {
            $ttl = (int) $this->globalConfig()->get('cache.ttl', 3600);
            $this->releaseHistory = new ReleaseHistoryClient($this->home . '/cache/release-history', $ttl);
        }
        return $this->releaseHistory;
    }

    public function composerMetadata(): ComposerMetadataClient
    {
        if ($this->composerMetadata === null) {
            $ttl = (int) $this->globalConfig()->get('cache.ttl', 3600);
            $this->composerMetadata = new ComposerMetadataClient($this->home . '/cache', $ttl);
        }
        return $this->composerMetadata;
    }

    public function locator(): SiteLocator
    {
        return new SiteLocator($this->registry());
    }

    public function discoverer(): SiteDiscoverer
    {
        return new SiteDiscoverer($this->globalConfig(), $this->processRunner());
    }

    /**
     * Builds the per-site context: config, execution environment, Composer and Drush runners.
     */
    public function forSite(Site $site, ?string $uri = null): SiteContext
    {
        $config = $this->configLoader()->load($site->root);
        $runner = $this->processRunner();
        $environment = EnvironmentDetector::detect($site->root, $config, $runner);
        $project = new ComposerProject($site->root);
        $timeout = (float) $config->get('upgrade.composer_timeout', 1800);
        $composer = new ComposerRunner($runner, $environment, $site->root, $timeout);

        $drushInfo = DrushDetector::detect($site->root, $project);
        $drush = $drushInfo === null
            ? null
            : new DrushRunner($runner, $environment, $site->root, $drushInfo, $uri ?? $site->uri);

        return new SiteContext($site, $config, $environment, $runner, $project, $composer, $drush);
    }
}
