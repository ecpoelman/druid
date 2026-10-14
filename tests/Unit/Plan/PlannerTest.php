<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Plan;

use Druid\Composer\ComposerProject;
use Druid\Config\Config;
use Druid\Plan\PlanOptions;
use Druid\Plan\Planner;
use Druid\Plan\Step;
use Druid\Scan\CoreStatus;
use Druid\Scan\PackageStatus;
use Druid\Scan\ScanResult;
use Druid\Security\Advisory;
use Druid\Security\Severity;
use Druid\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class PlannerTest extends TestCase
{
    private ComposerProject $project;

    protected function setUp(): void
    {
        $this->project = new ComposerProject(TempProject::create(['require' => [
            'drupal/core-recommended' => '^10.6',
            'drupal/webform' => '^6.2',
            'drupal/token' => '^1.13',
        ]]));
    }

    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    private static function scan(?string $coreSecurity = '10.6.11'): ScanResult
    {
        $coreAdvisory = new Advisory('SA-CORE-2026-001', 'drupal/core', 'Drupal core - Critical', Severity::Critical, '>=10.6.0 <10.6.11');
        return new ScanResult('acme', '/tmp/acme', date(DATE_ATOM), new CoreStatus(
            '10.6.9',
            $coreSecurity,
            '10.6.12',
            '10.6.12',
            '11.2.5',
            $coreSecurity !== null ? [$coreAdvisory] : [],
        ), [
            new PackageStatus('drupal/webform', 'drupal-module', true, false, '6.2.9', '6.2.12', '6.2.14', null, [
                new Advisory('SA-CONTRIB-2026-010', 'drupal/webform', 'Webform - Moderately critical', Severity::Moderate, '<6.2.12'),
            ]),
            new PackageStatus('drupal/token', 'drupal-module', true, false, '1.13.0', null, '1.15.0'),
            new PackageStatus('drupal/ctools', 'drupal-module', false, false, '4.1.0', null, '4.1.1'),
        ]);
    }

    private function planner(array $config = []): Planner
    {
        return new Planner(new Config(Config::merge(Config::defaults(), $config)), $this->project);
    }

    public function testSecurityScopeTargetsSafestReleasesCoreFirst(): void
    {
        $plan = $this->planner()->build(self::scan(), new PlanOptions(Step::SCOPE_SECURITY));
        self::assertSame(['core', 'package:drupal/webform'], array_map(static fn (Step $s) => $s->id, $plan->steps));
        self::assertSame('10.6.11', $plan->step('core')?->to);
        self::assertSame('6.2.12', $plan->step('package:drupal/webform')?->to);
        self::assertSame(Severity::Critical, $plan->step('core')?->severity);
    }

    public function testLatestPicksNewestPatchForSecurityFixes(): void
    {
        $plan = $this->planner()->build(self::scan(), new PlanOptions(Step::SCOPE_SECURITY, latest: true));
        self::assertSame('10.6.12', $plan->step('core')?->to);
        self::assertSame('6.2.14', $plan->step('package:drupal/webform')?->to);
    }

    public function testMinorScopeAddsDirectMinorUpdatesOnly(): void
    {
        $plan = $this->planner()->build(self::scan(null), new PlanOptions(Step::SCOPE_MINOR));
        $ids = array_map(static fn (Step $s) => $s->id, $plan->steps);
        self::assertSame(['package:drupal/webform', 'core', 'package:drupal/token'], $ids);
        self::assertFalse($plan->hasStep('package:drupal/ctools'), 'transitive packages are not bumped in minor scope');
    }

    public function testPackageFilterAndExplicitTarget(): void
    {
        $plan = $this->planner()->build(self::scan(), new PlanOptions(Step::SCOPE_MINOR, ['token'], false, true, false, '1.14.0'));
        self::assertCount(1, $plan->steps);
        self::assertSame('1.14.0', $plan->steps[0]->to);
    }

    public function testExcludedAndPinnedPackagesAreSkippedWithNotes(): void
    {
        $plan = $this->planner([
            'packages' => ['exclude' => ['drupal/webform'], 'pin' => ['drupal/core' => '~10.6.10']],
        ])->build(self::scan(), new PlanOptions(Step::SCOPE_SECURITY));
        self::assertTrue($plan->hasStep('core'));
        self::assertFalse($plan->hasStep('package:drupal/webform'));
        self::assertNotEmpty(array_filter($plan->notes, static fn (string $n) => str_contains($n, 'excluded')));

        $pinned = $this->planner(['packages' => ['pin' => ['drupal/core' => '~10.5.0']]])->build(self::scan(), new PlanOptions(Step::SCOPE_SECURITY));
        self::assertFalse($pinned->hasStep('core'));
    }

    public function testOrderPutsSecurityBeforeMinorAndCoreFirst(): void
    {
        $steps = Planner::order([
            new Step('package:b', Step::MODULE, Step::SCOPE_MINOR, 'b', 'b'),
            new Step('package:lib', Step::LIBRARY, Step::SCOPE_SECURITY, 'lib', 'lib'),
            new Step('core', Step::CORE, Step::SCOPE_MINOR, 'core', 'drupal/core'),
            new Step('package:a', Step::MODULE, Step::SCOPE_SECURITY, 'a', 'a', severity: Severity::High),
        ]);
        self::assertSame(['package:a', 'package:lib', 'core', 'package:b'], array_map(static fn (Step $s) => $s->id, $steps));
    }
}
