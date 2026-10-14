<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Composer;

use Druid\Composer\ComposerProject;
use Druid\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ComposerProjectTest extends TestCase
{
    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    public function testDetectsDirectCoreRequirement(): void
    {
        $project = new ComposerProject(TempProject::create(['require' => [
            'drupal/core' => '10.6.9',
            'drupal/core-recommended' => '10.6.9',
            'drupal/core-dev' => '10.6.9',
        ]]));
        self::assertTrue($project->hasDirectCorePin());
        self::assertTrue($project->hasExactCorePins());
        $args = $project->coreRequireArguments('10.6.11');
        self::assertContains('drupal/core:10.6.11', $args);
        self::assertContains('drupal/core-dev:10.6.11', $args);
        self::assertCount(3, $args);
    }

    public function testExactConstraintDetection(): void
    {
        self::assertTrue(ComposerProject::isExactVersionConstraint('10.6.9'));
        self::assertFalse(ComposerProject::isExactVersionConstraint('^10'));
        self::assertFalse(ComposerProject::isExactVersionConstraint('~10.6.0'));
    }

    public function testConstraintForKeepsStyle(): void
    {
        $project = new ComposerProject(TempProject::create(['require' => [
            'drupal/webform' => '^6.2',
            'drupal/admin_toolbar' => '3.5.1',
            'drupal/token' => '~1.13.0',
        ]]));
        self::assertSame('^6.2.12', $project->constraintFor('drupal/webform', '6.2.12'));
        self::assertSame('3.5.4', $project->constraintFor('drupal/admin_toolbar', '3.5.4'));
        self::assertSame('~1.15.0', $project->constraintFor('drupal/token', '1.15.0'));
        self::assertSame('^4.1.1', $project->constraintFor('drupal/ctools', '4.1.1'));
    }

    public function testDefaultCorePackagesWhenNoneDeclared(): void
    {
        $project = new ComposerProject(TempProject::create(['require' => ['drupal/token' => '^1']]));
        self::assertSame(ComposerProject::DEFAULT_CORE_PACKAGES, $project->pinnedCorePackages());
    }

    public function testLockAndDevRequirements(): void
    {
        $dir = TempProject::create(
            ['require' => ['drupal/core-recommended' => '^10.6'], 'require-dev' => ['drupal/core-dev' => '^10.6']],
            ['drupal/core' => '10.6.9', 'drupal/token@drupal-module' => 'v1.13.0'],
        );
        $project = new ComposerProject($dir);
        self::assertSame('10.6.9', $project->installedCoreVersion());
        self::assertSame('1.13.0', $project->lockedVersion('drupal/token'));
        self::assertSame('drupal-module', $project->lockPackages()['drupal/token']['type']);
        self::assertTrue($project->isDevRequirement('drupal/core-dev'));
        self::assertFalse($project->isDirect('drupal/token'));
        self::assertTrue($project->hasPackage('drupal/token'));
    }

    public function testRereadsFilesWhenTheyChange(): void
    {
        $dir = TempProject::create(['require' => []], ['drupal/token' => '1.13.0']);
        $project = new ComposerProject($dir);
        self::assertSame('1.13.0', $project->lockedVersion('drupal/token'));
        TempProject::writeLock($dir, ['drupal/token' => '1.15.0']);
        self::assertSame('1.15.0', $project->lockedVersion('drupal/token'));
    }

    public function testPluginsAndLenientList(): void
    {
        $project = new ComposerProject(TempProject::create([
            'config' => ['allow-plugins' => ['mglaman/composer-drupal-lenient' => true]],
            'extra' => ['drupal-lenient' => ['allowed-list' => ['drupal/foo']]],
        ]));
        self::assertTrue($project->pluginAllowed('mglaman/composer-drupal-lenient'));
        self::assertFalse($project->pluginAllowed('other/plugin'));
        self::assertSame(['drupal/foo'], $project->lenientAllowList());
    }
}
