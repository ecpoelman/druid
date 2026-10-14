<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Drupal;

use Druid\Composer\ComposerProject;
use Druid\Drupal\PhpRequirements;
use Druid\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class PhpRequirementsTest extends TestCase
{
    protected function tearDown(): void
    {
        TempProject::cleanup();
    }

    public function testComparesShortAndFullVersions(): void
    {
        self::assertTrue(PhpRequirements::below('8.2', '8.3.0'));
        self::assertTrue(PhpRequirements::below('8.2.29', '8.3.0'));
        self::assertFalse(PhpRequirements::below('8.3', '8.3.0'));
        self::assertFalse(PhpRequirements::below('8.4.1', '8.3.0'));
        self::assertFalse(PhpRequirements::below('not-a-version', '8.3.0'));
    }

    public function testDetectsPinnedPlatformAndPantheonPhp(): void
    {
        $root = TempProject::create(['name' => 'acme/site', 'config' => ['platform' => ['php' => '8.2']]]);
        file_put_contents($root . '/pantheon.yml', "api_version: 1\nphp_version: 8.2\n");
        file_put_contents($root . '/pantheon.upstream.yml', "api_version: 1\nphp_version: 7.4\n");
        $project = new ComposerProject($root);

        self::assertSame(['pantheon.yml' => '8.2'], PhpRequirements::hostingPhp($root));
        $problems = PhpRequirements::problems(11, '8.3.10', $project);
        self::assertCount(2, $problems);
        self::assertStringContainsString('config.platform.php to 8.2', $problems[0]);
        self::assertStringContainsString('pantheon.yml sets php_version to 8.2', $problems[1]);
        self::assertSame(['composer config platform.php 8.3', 'edit pantheon.yml: php_version: 8.3'], PhpRequirements::fixCommands(11, $project));
        self::assertSame([], PhpRequirements::problems(10, '8.3.10', $project));
    }

    public function testDetectsDdevPhpAndTheRunningPhp(): void
    {
        $root = TempProject::create(['name' => 'acme/site']);
        mkdir($root . '/.ddev');
        file_put_contents($root . '/.ddev/config.yaml', "name: acme\nphp_version: \"8.1\"\n");
        $project = new ComposerProject($root);

        $problems = PhpRequirements::problems(11, '8.2.0', $project);
        self::assertCount(2, $problems);
        self::assertStringContainsString('runs PHP 8.2.0', $problems[0]);
        self::assertSame(['ddev config --php-version=8.3 && ddev restart'], PhpRequirements::fixCommands(11, $project));
    }
}
