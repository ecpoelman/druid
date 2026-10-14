<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Drupal;

use Druid\Drupal\ComposerMetadataClient;
use Druid\Drupal\InfoFile;
use Druid\Drupal\Release;
use Druid\Drupal\ReleaseHistory;
use Druid\Drupal\RemovedCoreExtensions;
use Druid\Major\PhpstanRunner;
use Druid\Major\RectorRunner;
use Druid\Plan\MajorPlanner;
use Druid\Plan\Step;
use Druid\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class MajorCompatibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    private static function color(): ReleaseHistory
    {
        $release = static fn (string $version, string $compat): Release => new Release($version, $version, 'published', null, false, [], $compat);
        return new ReleaseHistory('color', 'Color', 'published', ['1.', '2.0.'], [
            $release('2.0.0-alpha1', '>=9.4 || ^10 || ^11'),
            $release('1.0.3', '>=9.4'),
        ]);
    }

    public function testParsesMinifiedComposerMetadata(): void
    {
        $json = json_encode([
            'minified' => 'composer/2.0',
            'packages' => ['drupal/color' => [
                ['name' => 'drupal/color', 'version' => '2.0.0-alpha1', 'require' => ['drupal/core' => '>=10.1.3 || ^11']],
                ['version' => '1.0.3', 'require' => ['drupal/core' => '^9.4 || ^10']],
                ['version' => '1.0.2'],
                ['version' => '1.0.1', 'require' => '__unset'],
            ]],
        ]);

        self::assertSame([
            '2.0.0-alpha1' => '>=10.1.3 || ^11',
            '1.0.3' => '^9.4 || ^10',
            '1.0.2' => '^9.4 || ^10',
            '1.0.1' => null,
        ], ComposerMetadataClient::parse('drupal/color', (string) $json));
    }

    public function testComposerMetadataWinsOverTheReleaseFeed(): void
    {
        // The feed claims 1.0.3 supports ">=9.4"; Composer knows it requires "^9.4 || ^10".
        self::assertSame(['action' => 'none', 'version' => null], MajorPlanner::decide(self::color(), '1.0.3', '10.6.18', '11.4.9'));

        $composer = ['2.0.0-alpha1' => '>=10.1.3 || ^11', '1.0.3' => '^9.4 || ^10'];
        self::assertSame(['action' => 'bridge', 'version' => '2.0.0-alpha1'], MajorPlanner::decide(self::color(), '1.0.3', '10.6.18', '11.4.9', $composer));
    }

    public function testRemovedCoreExtensionsAreMatchedPerMajor(): void
    {
        $enabled = ['node', 'tour', 'color', 'forum'];
        self::assertSame(['forum', 'tour'], array_keys(RemovedCoreExtensions::affecting($enabled, 10, 11)));
        self::assertSame(['color'], array_keys(RemovedCoreExtensions::affecting($enabled, 9, 10)));
        self::assertSame(['package' => 'drupal/tour', 'major' => 11], RemovedCoreExtensions::replacement('tour'));
        self::assertNull(RemovedCoreExtensions::replacement('node'));
    }

    public function testInfoFilesGainTheNewMajorAndKeepTheirStyle(): void
    {
        $root = TempProject::create(['name' => 'acme/site']);
        mkdir($root . '/web/modules/custom/quoted', 0777, true);
        mkdir($root . '/web/modules/custom/plain', 0777, true);
        mkdir($root . '/web/modules/custom/ready', 0777, true);
        file_put_contents($root . '/web/modules/custom/quoted/quoted.info.yml', "name: Quoted\ntype: module\ncore_version_requirement: '^9 || ^10'\n");
        file_put_contents($root . '/web/modules/custom/plain/plain.info.yml', "name: Plain\ncore: 8.x\ntype: module\n");
        file_put_contents($root . '/web/modules/custom/ready/ready.info.yml', "name: Ready\ncore_version_requirement: ^10 || ^11\n");
        $custom = [
            'quoted' => $root . '/web/modules/custom/quoted',
            'plain' => $root . '/web/modules/custom/plain',
            'ready' => $root . '/web/modules/custom/ready',
        ];

        $step = MajorPlanner::infoFilesStep($custom, $root, '10.6.18', '11.4.9', Step::SCOPE_MAJOR);
        self::assertNotNull($step);
        self::assertSame(['web/modules/custom/quoted/quoted.info.yml', 'web/modules/custom/plain/plain.info.yml'], $step->packages);

        self::assertSame('^9 || ^10 || ^11', InfoFile::declareSupport($root . '/' . $step->packages[0], 11, 10));
        self::assertStringContainsString("core_version_requirement: '^9 || ^10 || ^11'\n", (string) file_get_contents($root . '/' . $step->packages[0]));
        self::assertSame('^10 || ^11', InfoFile::declareSupport($root . '/' . $step->packages[1], 11, 10));
        self::assertNull(InfoFile::declareSupport($root . '/web/modules/custom/ready/ready.info.yml', 11, 10));
        self::assertNull(MajorPlanner::infoFilesStep($custom, $root, '10.6.18', '11.4.9', Step::SCOPE_MAJOR));
    }

    public function testRectorSkipsBundledThirdPartyCode(): void
    {
        $root = TempProject::create(['name' => 'acme/site']);
        mkdir($root . '/web/modules/custom/algolia/vendor', 0777, true);
        mkdir($root . '/web/modules/custom/algolia/src', 0777, true);
        touch($root . '/web/modules/custom/algolia/algolia.module');
        mkdir($root . '/web/modules/custom/site', 0777, true);

        self::assertSame(
            ['web/modules/custom/algolia/algolia.module', 'web/modules/custom/algolia/src', 'web/modules/custom/site'],
            RectorRunner::withoutBundledCode($root, ['web/modules/custom/algolia', 'web/modules/custom/site']),
        );
    }

    public function testPhpstanFindingsKeepOnlyUpgradeRelevantOnes(): void
    {
        $files = [
            '/site/web/modules/custom/solr/src/Search.php' => ['messages' => [
                ['line' => 80, 'message' => 'Function watchdog_exception not found.', 'identifier' => 'function.notFound'],
                ['line' => 33, 'message' => '\Drupal calls should be avoided in classes', 'identifier' => 'globalDrupalDependencyInjection.useDependencyInjection'],
            ]],
            '/site/web/themes/custom/nhra/nhra.theme' => ['messages' => [
                ['line' => 647, 'message' => 'Call to deprecated method get()', 'identifier' => 'method.deprecated'],
            ]],
        ];

        $issues = PhpstanRunner::issues($files, ['solr' => '/site/web/modules/custom/solr', 'nhra' => '/site/web/themes/custom/nhra']);

        self::assertCount(2, $issues);
        self::assertSame(['solr', 'error'], [$issues[0]['extension'], $issues[0]['severity']]);
        self::assertSame(['nhra', 'warning'], [$issues[1]['extension'], $issues[1]['severity']]);
    }
}
