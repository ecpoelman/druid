<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Upgrade;

use Druid\Composer\ComposerProject;
use Druid\Composer\ProblemParser;
use Druid\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class HiddenBlockersTest extends TestCase
{
    private const OUTPUT = <<<'OUT'
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires drupal/core-recommended 11.4.9 -> satisfiable by drupal/core-recommended[11.4.9].
    - drupal/core-recommended 11.4.9 requires symfony/console ~v7.4.13 -> found symfony/console[v7.4.0-BETA1, v7.4.13, ..., 7.4.x-dev] but these were not loaded, likely because it conflicts with another require.
  Problem 2
    - drupal/core 11.3.x-dev requires drupal/core-composer-scaffold 11.3.x-dev -> found drupal/core-composer-scaffold[11.3.x-dev] but it conflicts with your root composer.json require (11.4.9).
OUT;

    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    public function testFindsPackagesComposerFoundButDidNotLoad(): void
    {
        self::assertSame(['symfony/console' => '7.4.13'], ProblemParser::notLoaded(self::OUTPUT));
    }

    public function testFindsTheLockedPackagesHoldingThemBack(): void
    {
        $root = TempProject::create(['name' => 'acme/site']);
        file_put_contents($root . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'chi-teck/drupal-code-generator', 'version' => '3.6.1', 'require' => ['symfony/console' => '^6.3']],
                ['name' => 'drush/drush', 'version' => '13.8.0', 'require' => ['symfony/console' => '^6.4 || ^7']],
                ['name' => 'drupal/core', 'version' => '10.6.18', 'require' => ['symfony/console' => '^6.4']],
            ],
            'packages-dev' => [],
        ]));

        $requirers = (new ComposerProject($root))->lockedRequirersExcluding('symfony/console', '7.4.13');

        self::assertSame(['chi-teck/drupal-code-generator', 'drupal/core'], $requirers);
    }
}
