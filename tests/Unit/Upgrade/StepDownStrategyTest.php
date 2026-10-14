<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Upgrade;

use Druid\Plan\Step;
use Druid\Upgrade\Strategy\StepDownStrategy;
use Druid\Upgrade\Strategy\StrategyFactory;
use Druid\Config\Config;
use PHPUnit\Framework\TestCase;

final class StepDownStrategyTest extends TestCase
{
    private const RELEASES = ['6.3.0-beta1', '6.2.14', '6.2.13', '6.2.12', '6.2.11', '6.2.10', '6.2.9', '6.1.0'];

    public function testMinorStepsTryLowerReleasesClosestFirst(): void
    {
        $step = new Step('package:drupal/webform', Step::MODULE, Step::SCOPE_MINOR, 'webform', 'drupal/webform', '6.2.9', '6.2.14');
        self::assertSame(['6.2.13', '6.2.12', '6.2.11'], StepDownStrategy::candidates($step, self::RELEASES, 3));
    }

    public function testSecurityStepsSkipAffectedReleasesAndTryNewerPatches(): void
    {
        $step = new Step('package:drupal/webform', Step::MODULE, Step::SCOPE_SECURITY, 'webform', 'drupal/webform', '6.2.9', '6.2.12', affected: ['<6.2.11']);
        self::assertSame(['6.2.11', '6.2.13', '6.2.14'], StepDownStrategy::candidates($step, self::RELEASES, 3));
    }

    public function testFactoryHonoursConfiguredOrder(): void
    {
        $config = new Config(Config::merge(Config::defaults(), ['upgrade' => ['strategies' => ['all_dependencies', 'isolated', 'bogus']]]));
        $names = array_map(static fn ($s) => $s->name(), StrategyFactory::fromConfig($config));
        self::assertSame(['all_dependencies', 'isolated'], $names);
    }

    public function testFactoryDefaultLadder(): void
    {
        $names = array_map(static fn ($s) => $s->name(), StrategyFactory::fromConfig(new Config(Config::defaults())));
        self::assertSame(['isolated', 'require_bump', 'blockers', 'all_dependencies', 'no_audit_blocking', 'core_first', 'step_down', 'lenient'], $names);
    }
}
