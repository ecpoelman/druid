<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Upgrade;

use Druid\Composer\ComposerProject;
use Druid\Plan\Step;
use Druid\Tests\Support\TempProject;
use Druid\Upgrade\Diagnosis;
use Druid\Upgrade\FailureAnalyzer;
use PHPUnit\Framework\TestCase;

final class FailureAnalyzerTest extends TestCase
{
    private FailureAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new FailureAnalyzer(new ComposerProject(TempProject::create(['name' => 'acme/site', 'require' => ['drupal/core-recommended' => '^10']])));
    }

    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    private static function core(string $kind = Step::CORE): Step
    {
        return new Step('core', $kind, Step::SCOPE_SECURITY, 'core', 'drupal/core', '10.6.9', '10.6.11');
    }

    private static function module(): Step
    {
        return new Step('package:drupal/webform', Step::MODULE, Step::SCOPE_MINOR, 'webform', 'drupal/webform', '6.2.0', '6.3.0');
    }

    public function testDetectsAuditBlockedErrors(): void
    {
        $diagnosis = $this->analyzer->analyze('these were not loaded, because they are affected by security advisories', self::core());
        self::assertSame(Diagnosis::AUDIT_BLOCKED, $diagnosis->kind);
        self::assertTrue($diagnosis->isAuditBlocked());
    }

    public function testDetectsNetworkProblems(): void
    {
        self::assertSame(Diagnosis::NETWORK, $this->analyzer->analyze('curl error 28 while downloading https://packagist.org', self::module())->kind);
    }

    public function testDetectsMemoryExhaustion(): void
    {
        self::assertSame(Diagnosis::MEMORY, $this->analyzer->analyze('PHP Fatal error:  Allowed memory size of 1610612736 bytes exhausted', self::module())->kind);
    }

    public function testDetectsScaffoldPermissions(): void
    {
        $output = 'Could not delete web/sites/default/default.settings.php: Permission denied';
        self::assertSame(Diagnosis::SCAFFOLD_PERMISSIONS, $this->analyzer->analyze($output, self::core())->kind);
    }

    public function testDetectsNeedsNewerCore(): void
    {
        $output = <<<'OUT'
Your requirements could not be resolved to an installable set of packages.
  Problem 1
    - drupal/webform 6.3.0 requires drupal/core ^10.3 || ^11 -> found drupal/core[10.2.0] but it conflicts with your root composer.json require (10.2.0).
OUT;
        $diagnosis = $this->analyzer->analyze($output, self::module());
        self::assertSame(Diagnosis::NEEDS_NEWER_CORE, $diagnosis->kind);
        self::assertTrue($diagnosis->needsCore());
    }

    public function testDetectsContribBlockingMajorCore(): void
    {
        $output = <<<'OUT'
Your requirements could not be resolved to an installable set of packages.
  Problem 1
    - drupal/delete_all 3.0.0 requires drupal/core ^9 || ^10 -> found drupal/core[11.2.5] but it does not match the constraint.
OUT;
        $diagnosis = $this->analyzer->analyze($output, self::core(Step::MAJOR_CORE));
        self::assertSame(Diagnosis::CONTRIB_BLOCKS_CORE, $diagnosis->kind);
        self::assertSame(['drupal/delete_all'], $diagnosis->blockers);
    }

    public function testDetectsPatchFailures(): void
    {
        self::assertSame(Diagnosis::PATCH_FAILED, $this->analyzer->analyze('Cannot apply patch Fix the thing (https://www.drupal.org/files/x.patch)!', self::module())->kind);
    }

    public function testDetectsBlockedComposerPlugin(): void
    {
        $output = <<<'OUT'
In PluginManager.php line 821:

  symfony/runtime contains a Composer plugin which is blocked by your allow-p
  lugins config. You may add it to the list if you consider it safe.
OUT;
        self::assertSame('symfony/runtime', FailureAnalyzer::blockedPlugin('symfony/runtime contains a Composer plugin which is blocked by your allow-plugins config.'));
        $diagnosis = $this->analyzer->analyze($output, self::core(Step::MAJOR_CORE));
        self::assertSame(Diagnosis::PLUGIN_BLOCKED, $diagnosis->kind);
        self::assertSame(['symfony/runtime'], $diagnosis->blockers);
    }

    public function testSummaryPicksTheMostUsefulLine(): void
    {
        $output = "Loading composer repositories\nYour requirements could not be resolved to an installable set of packages.\n  Problem 1";
        self::assertSame('Your requirements could not be resolved to an installable set of packages.', FailureAnalyzer::summary($output));
        self::assertSame('Composer failed', FailureAnalyzer::summary(''));
    }
}
