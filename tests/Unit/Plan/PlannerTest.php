<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Plan;

use Druid\Composer\ComposerProject;
use Druid\Config\Config;
use Druid\Drupal\ReleaseHistoryClient;
use Druid\Plan\MajorPlanner;
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
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

    public function testMajorRunsCoreFirstThenContribThenCustomCode(): void
    {
        $root = TempProject::create(
            ['require' => ['drupal/core-recommended' => '^10.6', 'drupal/webform' => '^6.2', 'drupal/token' => '^1.13', 'drupal/linkit' => '^6.1']],
            ['drupal/core' => '10.6.9', 'drupal/webform@drupal-module' => '6.2.9', 'drupal/token@drupal-module' => '1.13.0', 'drupal/linkit@drupal-module' => '6.1.7'],
        );
        mkdir($root . '/web/modules/custom/site', 0777, true);
        file_put_contents($root . '/web/modules/custom/site/site.info.yml', "name: Site\ntype: module\ncore_version_requirement: ^10\n");
        $project = new ComposerProject($root);
        $linkit = (string) file_get_contents(__DIR__ . '/../../Fixtures/release-history-linkit.xml');
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_contains($url, '/linkit/')
            ? new MockResponse($linkit)
            : new MockResponse('', ['http_code' => 404]));
        $planner = new Planner(
            new Config(Config::defaults()),
            $project,
            new MajorPlanner($project, new ReleaseHistoryClient($root . '/.cache', 3600, $http)),
        );

        $base = self::scan();
        $scan = new ScanResult('acme', $root, $base->scannedAt, $base->core, [
            ...$base->packages,
            new PackageStatus('drupal/linkit', 'drupal-module', true, false, '6.1.7', null, null),
        ], customModules: ['site' => $root . '/web/modules/custom/site']);

        $plan = $planner->build($scan, new PlanOptions(Step::SCOPE_MAJOR, target: '11.2.5'));

        self::assertSame(
            ['readiness', 'core', 'major-core', 'package:drupal/webform', 'package:drupal/token', 'rector', 'info-files'],
            array_map(static fn (Step $s) => $s->id, $plan->steps),
        );
        self::assertSame(['drupal/linkit' => '^7.0.2'], $plan->step('major-core')?->companions);
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
