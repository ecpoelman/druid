<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Upgrade;

use Druid\Composer\ComposerProject;
use Druid\Composer\ComposerRunner;
use Druid\Config\Config;
use Druid\Environment\NativeEnvironment;
use Druid\Plan\Step;
use Druid\Plan\UpgradePlan;
use Druid\Site\Site;
use Druid\SiteContext;
use Druid\Tests\Support\RecordingListener;
use Druid\Tests\Support\ScriptedProcessRunner;
use Druid\Tests\Support\TempProject;
use Druid\Upgrade\CommandBuilder;
use Druid\Upgrade\Engine;
use Druid\Upgrade\EngineOptions;
use Druid\Upgrade\RunResult;
use Druid\Upgrade\SnapshotStore;
use Druid\Upgrade\StepResult;
use Druid\Upgrade\Strategy\StrategyFactory;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    private const CONFLICT = <<<'OUT'
Your requirements could not be resolved to an installable set of packages.
  Problem 1
    - drupal/webform 6.2.12 requires drupal/token ^1.14 -> found drupal/token[1.14.0] but the package is fixed to 1.13.0 (lock file version).
    - drupal/token is locked to version 1.13.0 and an update of this package was not requested.
OUT;

    private string $root;
    private ScriptedProcessRunner $runner;
    private RecordingListener $listener;

    protected function setUp(): void
    {
        $this->root = TempProject::create(
            ['name' => 'acme/site', 'require' => ['drupal/core-recommended' => '^10.6', 'drupal/webform' => '^6.2', 'drupal/pathauto' => '^1.12']],
            ['drupal/core' => '10.6.9', 'drupal/webform@drupal-module' => '6.2.9', 'drupal/token@drupal-module' => '1.13.0', 'drupal/pathauto@drupal-module' => '1.12.0'],
        );
        $this->runner = new ScriptedProcessRunner();
        $this->listener = new RecordingListener();
        $this->runner
            ->on(static fn (array $c) => $c[0] === 'git' ? ScriptedProcessRunner::fail('git', 'not a git repository', 128) : null)
            ->on(static fn (array $c) => ($c[1] ?? '') === '--version' ? ScriptedProcessRunner::ok('composer', 'Composer version 2.8.4 2024-12-11') : null)
            ->on(static fn (array $c) => ($c[1] ?? '') === 'validate' ? ScriptedProcessRunner::ok('composer validate', './composer.json is valid') : null)
            ->on(static fn (array $c) => ($c[1] ?? '') === 'why-not' ? ScriptedProcessRunner::fail('why-not', '') : null)
            ->on(static fn (array $c) => ($c[1] ?? '') === 'install' ? ScriptedProcessRunner::ok('composer install') : null);
    }

    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    private function runPlan(UpgradePlan $plan, array $config = []): RunResult
    {
        $project = new ComposerProject($this->root);
        $context = new SiteContext(
            new Site('acme', $this->root),
            new Config(Config::merge(Config::defaults(), $config)),
            new NativeEnvironment(),
            $this->runner,
            $project,
            new ComposerRunner($this->runner, new NativeEnvironment(), $this->root),
            null,
        );
        $engine = new Engine(
            $context,
            new CommandBuilder($project, true),
            StrategyFactory::fromConfig($context->config),
            [],
            new SnapshotStore($this->root . '/.snapshots'),
            $this->listener,
        );
        return $engine->run($plan, new EngineOptions(backup: false));
    }

    private function lockVersion(string $package, string $version): void
    {
        $lock = json_decode((string) file_get_contents($this->root . '/composer.lock'), true);
        foreach ($lock['packages'] as &$item) {
            if ($item['name'] === $package) {
                $item['version'] = $version;
            }
        }
        file_put_contents($this->root . '/composer.lock', json_encode($lock));
    }

    private static function webform(string $scope = Step::SCOPE_SECURITY): Step
    {
        return new Step('package:drupal/webform', Step::MODULE, $scope, 'webform', 'drupal/webform', '6.2.9', '6.2.12', affected: ['<6.2.12']);
    }

    public function testClimbsTheLadderUntilBlockersAreUpdatedTogether(): void
    {
        $json = (string) file_get_contents($this->root . '/composer.json');
        $this->runner->on(function (array $c): ?\Druid\Environment\ProcessResult {
            if (in_array('drupal/token', $c, true)) {
                $this->lockVersion('drupal/webform', '6.2.12');
                $this->lockVersion('drupal/token', '1.14.0');
                return ScriptedProcessRunner::ok('update', 'Package operations: 0 installs, 2 updates, 0 removals');
            }
            if (($c[1] ?? '') === 'require') {
                file_put_contents($this->root . '/composer.json', '{"require":{"drupal/webform":"^6.2.12"}}');
            }
            return ScriptedProcessRunner::fail('composer', self::CONFLICT, 2);
        });

        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_SECURITY, [self::webform()]));

        self::assertSame(['isolated', 'require_bump', 'blockers'], $this->listener->strategies);
        self::assertSame(StepResult::UPGRADED, $result->steps[0]->status);
        self::assertSame('6.2.12', $result->steps[0]->achieved);
        self::assertSame('blockers', $result->steps[0]->strategy);
        self::assertSame($json, file_get_contents($this->root . '/composer.json'), 'failed require attempt was rolled back');
        self::assertSame(0, $result->exitCode());
        self::assertNotNull($result->snapshot);
        self::assertFileExists($result->snapshot->path . '/composer.lock');
    }

    public function testSecurityStepsRunWithAuditBlockingBypassed(): void
    {
        $this->runner->on(function (array $c, array $env): ?\Druid\Environment\ProcessResult {
            if (($c[1] ?? '') === 'update') {
                $this->lockVersion('drupal/webform', '6.2.12');
                return ScriptedProcessRunner::ok();
            }
            return null;
        });
        $this->runPlan(new UpgradePlan('acme', Step::SCOPE_SECURITY, [self::webform()]));

        $update = array_values(array_filter($this->runner->calls, static fn ($call) => ($call['command'][1] ?? '') === 'update'));
        self::assertSame(CommandBuilder::NO_AUDIT_BLOCKING_ENV, $update[0]['env']);
    }

    public function testFailingStepIsDeferredAndTheRunContinues(): void
    {
        $this->runner->on(function (array $c): ?\Druid\Environment\ProcessResult {
            if (in_array('drupal/pathauto:1.13.0', $c, true)) {
                $this->lockVersion('drupal/pathauto', '1.13.0');
                return ScriptedProcessRunner::ok();
            }
            return ($c[1] ?? '') === 'update' || ($c[1] ?? '') === 'require'
                ? ScriptedProcessRunner::fail('composer', self::CONFLICT, 2)
                : null;
        });
        $pathauto = new Step('package:drupal/pathauto', Step::MODULE, Step::SCOPE_MINOR, 'pathauto', 'drupal/pathauto', '1.12.0', '1.13.0');

        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_MINOR, [self::webform(Step::SCOPE_MINOR), $pathauto]));

        self::assertSame(StepResult::DEFERRED, $result->steps[0]->status);
        self::assertNotNull($result->steps[0]->diagnosis);
        self::assertGreaterThan(3, $result->steps[0]->attempts);
        self::assertSame(StepResult::UPGRADED, $result->steps[1]->status);
        self::assertSame(2, $result->exitCode());
        self::assertSame('6.2.9', (new ComposerProject($this->root))->lockedVersion('drupal/webform'));
    }

    public function testTrustedPluginIsAllowedAndTheCommandRetried(): void
    {
        $allowed = false;
        $this->runner->on(function (array $c) use (&$allowed): ?\Druid\Environment\ProcessResult {
            if (($c[1] ?? '') === 'config' && in_array('allow-plugins.symfony/runtime', $c, true)) {
                $allowed = true;
                return ScriptedProcessRunner::ok();
            }
            if (($c[1] ?? '') === 'update') {
                if (!$allowed) {
                    return ScriptedProcessRunner::fail('update', 'symfony/runtime contains a Composer plugin which is blocked by your allow-plugins config.');
                }
                $this->lockVersion('drupal/webform', '6.2.12');
                return ScriptedProcessRunner::ok();
            }
            return null;
        });

        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_SECURITY, [self::webform()]));

        self::assertTrue($allowed);
        self::assertSame(StepResult::UPGRADED, $result->steps[0]->status);
        self::assertSame(['isolated'], $this->listener->strategies);
    }

    public function testUntrustedPluginDefersWithTheCommandToRun(): void
    {
        $this->runner->on(static fn (array $c) => ($c[1] ?? '') === 'update'
            ? ScriptedProcessRunner::fail('update', 'evil/plugin contains a Composer plugin which is blocked by your allow-plugins config.')
            : null);

        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_SECURITY, [self::webform()]));

        self::assertSame(StepResult::DEFERRED, $result->steps[0]->status);
        self::assertSame(1, $result->steps[0]->attempts);
        self::assertContains('composer config --no-plugins allow-plugins.evil/plugin true', $result->steps[0]->diagnosis?->suggestedCommands ?? []);
    }

    public function testAuditBlockingDiscoveredLateTriggersASecondPass(): void
    {
        $this->runner->on(function (array $c, array $env): ?\Druid\Environment\ProcessResult {
            if (($c[1] ?? '') !== 'update' && ($c[1] ?? '') !== 'require') {
                return null;
            }
            if (in_array('--with-all-dependencies', $c, true) && !isset($env['COMPOSER_NO_SECURITY_BLOCKING'])) {
                return ScriptedProcessRunner::fail('update', "Your requirements could not be resolved.\n - drupal/token 1.14.0 but these were not loaded, because they are affected by security advisories");
            }
            if (isset($env['COMPOSER_NO_SECURITY_BLOCKING']) && ($c[1] ?? '') === 'update' && in_array('--with-dependencies', $c, true)) {
                $this->lockVersion('drupal/webform', '6.2.12');
                return ScriptedProcessRunner::ok();
            }
            return ScriptedProcessRunner::fail('update', self::CONFLICT, 2);
        });

        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_MINOR, [self::webform(Step::SCOPE_MINOR)]));
        self::assertSame(StepResult::UPGRADED, $result->steps[0]->status);
        self::assertSame('no_audit_blocking', $result->steps[0]->strategy);

        $this->lockVersion('drupal/webform', '6.2.9');
        $this->listener = new RecordingListener();
        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_MINOR, [self::webform(Step::SCOPE_MINOR)]), ['upgrade' => ['strategies' => ['isolated', 'all_dependencies']]]);
        self::assertSame(StepResult::UPGRADED, $result->steps[0]->status);
        self::assertSame(['isolated', 'all_dependencies', 'isolated'], $this->listener->strategies);
        self::assertContains('info: Retrying the strategies with what the failures revealed (COMPOSER_NO_BLOCKING, COMPOSER_NO_SECURITY_BLOCKING)', $this->listener->events);
    }

    public function testMajorCoreIsDeferredUpFrontWhenPhpIsPinnedTooLow(): void
    {
        $json = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        $json['config'] = ['platform' => ['php' => '8.2']];
        file_put_contents($this->root . '/composer.json', json_encode($json));
        $major = new Step('major-core', Step::MAJOR_CORE, Step::SCOPE_MAJOR, 'Drupal core 10.6.9 → 11.4.9', 'drupal/core', '10.6.9', '11.4.9', critical: true);

        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_MAJOR, [$major]));

        self::assertSame(StepResult::DEFERRED, $result->steps[0]->status);
        self::assertSame(0, $result->steps[0]->attempts);
        self::assertSame([], $this->listener->strategies);
        self::assertSame(\Druid\Upgrade\Diagnosis::PHP_REQUIREMENT, $result->steps[0]->diagnosis?->kind);
        self::assertContains('composer config platform.php 8.3', $result->steps[0]->diagnosis->suggestedCommands);
    }

    public function testStepsThatNeededNewerCoreAreRetriedAfterCoreMoves(): void
    {
        $coreMoved = false;
        $this->runner->on(function (array $c) use (&$coreMoved): ?\Druid\Environment\ProcessResult {
            if (($c[1] ?? '') !== 'update' && ($c[1] ?? '') !== 'require') {
                return null;
            }
            $args = implode(' ', $c);
            if (str_contains($args, 'drupal/pathauto')) {
                if (!$coreMoved) {
                    return ScriptedProcessRunner::fail('update', "Your requirements could not be resolved to an installable set of packages.\n  Problem 1\n    - drupal/pathauto 1.13.0 requires drupal/core ^10.7 -> found drupal/core[10.7.0] but it conflicts with your root composer.json require.", 2);
                }
                $this->lockVersion('drupal/pathauto', '1.13.0');
                return ScriptedProcessRunner::ok();
            }
            if (str_contains($args, 'drupal/core-recommended')) {
                $coreMoved = true;
                $this->lockVersion('drupal/core', '10.7.0');
                return ScriptedProcessRunner::ok();
            }
            return ScriptedProcessRunner::fail('update', self::CONFLICT, 2);
        });
        $pathauto = new Step('package:drupal/pathauto', Step::MODULE, Step::SCOPE_MINOR, 'pathauto', 'drupal/pathauto', '1.12.0', '1.13.0');
        $core = new Step('core', Step::CORE, Step::SCOPE_MINOR, 'Drupal core 10.6.9 → 10.7.0', 'drupal/core', '10.6.9', '10.7.0', critical: true);

        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_MINOR, [$pathauto, $core]));

        self::assertSame(StepResult::UPGRADED, $result->steps[1]->status);
        self::assertSame(StepResult::UPGRADED, $result->steps[0]->status);
        self::assertContains('info: Needs a newer core - deferred until the core step later in this run, then retried.', $this->listener->events);
        self::assertContains('info: Core has moved - retrying drupal/pathauto', $this->listener->events);
        self::assertSame(0, $result->exitCode());
    }

    public function testAlreadySatisfiedStepsRunNothing(): void
    {
        $this->lockVersion('drupal/webform', '6.2.12');
        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_SECURITY, [self::webform()]));
        self::assertSame(StepResult::SATISFIED, $result->steps[0]->status);
        self::assertSame([], $this->listener->strategies);
    }

    public function testMissingComposerIsFatal(): void
    {
        $runner = new ScriptedProcessRunner();
        $runner->on(static fn () => ScriptedProcessRunner::fail('composer', 'not found', 127));
        $this->runner = $runner;
        $result = $this->runPlan(new UpgradePlan('acme', Step::SCOPE_SECURITY, [self::webform()]));
        self::assertNotNull($result->fatal);
        self::assertSame(1, $result->exitCode());
        self::assertSame([], $result->steps);
    }
}
