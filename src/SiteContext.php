<?php

declare(strict_types=1);

namespace Druid;

use Druid\Composer\ComposerProject;
use Druid\Composer\ComposerRunner;
use Druid\Config\Config;
use Druid\Drush\DrushRunner;
use Druid\Environment\ExecutionEnvironment;
use Druid\Environment\ProcessRunner;
use Druid\Site\Site;

/**
 * Everything needed to inspect and change one Drupal project.
 */
final class SiteContext
{
    public function __construct(
        public readonly Site $site,
        public readonly Config $config,
        public readonly ExecutionEnvironment $environment,
        public readonly ProcessRunner $runner,
        public readonly ComposerProject $project,
        public readonly ComposerRunner $composer,
        public readonly ?DrushRunner $drush,
    ) {
    }

    public function root(): string
    {
        return $this->site->root;
    }
}
