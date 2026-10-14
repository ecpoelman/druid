<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Config;

use Druid\Config\Config;
use Druid\Config\ConfigLoader;
use Druid\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    public function testDotNotationAndDefaults(): void
    {
        $config = new Config(Config::defaults());
        self::assertTrue($config->bool('upgrade.backup'));
        self::assertSame('auto', $config->get('environment'));
        self::assertSame(['updatedb', 'cache:rebuild'], $config->list('upgrade.post_update'));
        self::assertSame('fallback', $config->get('missing.key', 'fallback'));
    }

    public function testMergeReplacesListsAndMergesMaps(): void
    {
        $merged = Config::merge(
            ['upgrade' => ['strategies' => ['a', 'b'], 'backup' => true], 'packages' => ['pin' => ['x' => '1']]],
            ['upgrade' => ['strategies' => ['c']], 'packages' => ['pin' => ['y' => '2']]],
        );
        self::assertSame(['c'], $merged['upgrade']['strategies']);
        self::assertTrue($merged['upgrade']['backup']);
        self::assertSame(['x' => '1', 'y' => '2'], $merged['packages']['pin']);
    }

    public function testProjectFileOverridesGlobalFile(): void
    {
        $home = TempProject::create([]);
        file_put_contents($home . '/druid.yml', "upgrade:\n  backup: false\n  verify: false\n");
        $project = TempProject::create([]);
        file_put_contents($project . '/druid.yml', "upgrade:\n  verify: true\npackages:\n  exclude: [drupal/foo]\n");

        $loader = new ConfigLoader($home);
        $config = $loader->load($project);
        self::assertFalse($config->bool('upgrade.backup', true));
        self::assertTrue($config->bool('upgrade.verify'));
        self::assertSame(['drupal/foo'], $config->list('packages.exclude'));
        self::assertCount(2, $loader->loadedFiles());
    }
}
