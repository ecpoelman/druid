<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Upgrade;

use Druid\Composer\ComposerProject;
use Druid\Plan\Step;
use Druid\Tests\Support\TempProject;
use Druid\Upgrade\CommandBuilder;
use PHPUnit\Framework\TestCase;

final class CommandBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    private static function module(string $package, string $from, string $to, string $scope = Step::SCOPE_MINOR): Step
    {
        return new Step('package:' . $package, Step::MODULE, $scope, $package, $package, $from, $to);
    }

    private static function core(string $from, string $to, string $kind = Step::CORE, array $companions = []): Step
    {
        return new Step('core', $kind, Step::SCOPE_SECURITY, 'core', 'drupal/core', $from, $to, companions: $companions);
    }

    public function testModuleUpdateIsIsolatedAndRespectsConstraintStyle(): void
    {
        $builder = new CommandBuilder(new ComposerProject(TempProject::create(['require' => [
            'drupal/webform' => '^6.2',
            'drupal/admin_toolbar' => '3.5.1',
            'drupal/token' => '~1.13.0',
        ]])), true);

        $webform = $builder->isolated(self::module('drupal/webform', '6.2.0', '6.2.12'))->args;
        self::assertSame(['update', 'drupal/webform:6.2.12'], array_slice($webform, 0, 2));
        self::assertContains('--with-dependencies', $webform);
        self::assertContains('--minimal-changes', $webform);
        self::assertNotContains('--with-all-dependencies', $webform);

        $toolbar = $builder->isolated(self::module('drupal/admin_toolbar', '3.5.1', '3.5.4'))->args;
        self::assertSame(['require', 'drupal/admin_toolbar:3.5.4'], array_slice($toolbar, 0, 2));

        $token = $builder->isolated(self::module('drupal/token', '1.13.0', '1.15.0'))->args;
        self::assertSame(['require', 'drupal/token:~1.15.0'], array_slice($token, 0, 2));

        $transitive = $builder->isolated(self::module('drupal/ctools', '4.1.0', '4.1.1'))->args;
        self::assertSame(['update', 'drupal/ctools:4.1.1'], array_slice($transitive, 0, 2));

        $new = $builder->isolated(new Step('package:drupal/tour', Step::MODULE, Step::SCOPE_MINOR, 'tour', 'drupal/tour', null, '2.0.6'))->args;
        self::assertSame(['require', 'drupal/tour:^2.0.6'], array_slice($new, 0, 2));
    }

    public function testBatchRaisesOnlyConstraintsThatBlockTheirTarget(): void
    {
        $builder = new CommandBuilder(new ComposerProject(TempProject::create([
            'require' => ['drupal/webform' => '^6.2', 'drupal/admin_toolbar' => '3.5.1', 'drupal/token' => '~1.13.0'],
            'require-dev' => ['drupal/devel' => '^5.1'],
        ])), true);

        $attempt = $builder->batch([
            self::module('drupal/webform', '6.2.0', '6.2.12'),
            self::module('drupal/admin_toolbar', '3.5.1', '3.5.4'),
            self::module('drupal/token', '1.13.0', '1.15.0'),
            self::module('drupal/ctools', '4.1.0', '4.1.1'),
            self::module('drupal/devel', '5.1.0', '6.0.0'),
        ]);

        self::assertSame('batch', $attempt->strategy);
        self::assertSame(
            ['update', 'drupal/webform:6.2.12', 'drupal/admin_toolbar:3.5.4', 'drupal/token:1.15.0', 'drupal/ctools:4.1.1', 'drupal/devel:6.0.0', '--with-dependencies', '--minimal-changes'],
            $attempt->args,
        );
        self::assertCount(2, $attempt->prerequisites);
        self::assertSame(['require', 'drupal/admin_toolbar:3.5.4', 'drupal/token:~1.15.0', '--no-update'], $attempt->prerequisites[0]->args);
        self::assertSame(['require', '--dev', 'drupal/devel:^6.0.0', '--no-update'], $attempt->prerequisites[1]->args);
    }

    public function testRequireBumpSkipsTransitivePackages(): void
    {
        $builder = new CommandBuilder(new ComposerProject(TempProject::create(['require' => ['drupal/webform' => '^6.2']])), false);
        self::assertNull($builder->requireBump(self::module('drupal/ctools', '4.1.0', '4.1.1')));
        $bump = $builder->requireBump(self::module('drupal/webform', '6.2.0', '6.2.12'));
        self::assertNotNull($bump);
        self::assertSame(['require', 'drupal/webform:^6.2.12', '--with-dependencies'], $bump->args);
    }

    public function testDevPackagesStayInRequireDev(): void
    {
        $builder = new CommandBuilder(new ComposerProject(TempProject::create(['require-dev' => ['drupal/devel' => '5.4.0']])), false);
        self::assertContains('--dev', $builder->isolated(self::module('drupal/devel', '5.4.0', '5.5.0'))->args);
    }

    public function testCoreUpdateWithinConstraintsKeepsComposerJson(): void
    {
        $builder = new CommandBuilder(new ComposerProject(TempProject::create(['require' => [
            'drupal/core-recommended' => '^10.6',
            'drupal/core-composer-scaffold' => '^10.6',
        ]])), true);
        $attempt = $builder->isolated(self::core('10.6.9', '10.6.11'));
        self::assertSame([], $attempt->prerequisites);
        self::assertSame(['update', 'drupal/core-composer-scaffold:10.6.11', 'drupal/core-recommended:10.6.11', '--with-all-dependencies'], $attempt->args);
    }

    public function testExactCorePinsAreRaisedPerSectionFirst(): void
    {
        $builder = new CommandBuilder(new ComposerProject(TempProject::create([
            'require' => ['drupal/core' => '10.6.9', 'drupal/core-recommended' => '^10'],
            'require-dev' => ['drupal/core-dev' => '10.6.9'],
        ])), true);
        $attempt = $builder->isolated(self::core('10.6.9', '10.6.11'));
        self::assertCount(2, $attempt->prerequisites);
        self::assertSame(['require', 'drupal/core:10.6.11', 'drupal/core-recommended:^10.6.11', '--no-update'], $attempt->prerequisites[0]->args);
        self::assertSame(['require', '--dev', 'drupal/core-dev:10.6.11', '--no-update'], $attempt->prerequisites[1]->args);
        self::assertSame('update', $attempt->args[0]);
        self::assertContains('drupal/core:10.6.11', $attempt->args);
        self::assertContains('--with-all-dependencies', $attempt->args);
    }

    public function testMajorCoreUsesMajorMinorConstraintAndCompanions(): void
    {
        $builder = new CommandBuilder(new ComposerProject(TempProject::create(['require' => [
            'drupal/core-recommended' => '^10.6',
            'drush/drush' => '^12',
        ]])), true);
        $attempt = $builder->isolated(self::core('10.6.18', '11.2.5', Step::MAJOR_CORE, ['drush/drush' => '^13']));
        self::assertSame(['require', 'drupal/core-recommended:^11.2', 'drush/drush:^13', '--no-update'], $attempt->prerequisites[0]->args);
        self::assertSame(['update', 'drupal/core-recommended:11.2.5', 'drush/drush', '--with-all-dependencies'], $attempt->args);
    }

    public function testAllDependenciesRaisesConstraintWithoutUpdatingFirst(): void
    {
        $builder = new CommandBuilder(new ComposerProject(TempProject::create(['require' => ['drupal/linkit' => '^6.1']])), true);
        $attempt = $builder->allDependencies(self::module('drupal/linkit', '6.1.7', '7.0.17', Step::SCOPE_MAJOR));
        self::assertSame(['require', 'drupal/linkit:^7.0.17', '--no-update'], $attempt->prerequisites[0]->args);
        self::assertSame(['update', 'drupal/linkit:7.0.17', '--with-all-dependencies'], $attempt->args);
    }

    public function testSecurityStepsBypassAuditBlocking(): void
    {
        $security = self::module('drupal/webform', '6.2.0', '6.2.12', Step::SCOPE_SECURITY);
        self::assertSame(CommandBuilder::NO_AUDIT_BLOCKING_ENV, CommandBuilder::baseEnv($security, true));
        self::assertSame([], CommandBuilder::baseEnv($security, false));
        self::assertSame([], CommandBuilder::baseEnv(self::module('drupal/webform', '6.2.0', '6.2.12'), true));
    }
}
