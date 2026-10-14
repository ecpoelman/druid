<?php

declare(strict_types=1);

namespace Druid\Tests\Unit;

use Druid\Application;
use Druid\Launcher;
use Druid\Services;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testRewritesFirstAliasToSiteOption(): void
    {
        self::assertSame(['druid', '--site=mcv', 'scan'], Application::rewriteAliasArguments(['druid', '@mcv', 'scan']));
        self::assertSame(['druid', 'plan', '--site=my.site', '--minor'], Application::rewriteAliasArguments(['druid', 'plan', '@my.site', '--minor']));
        self::assertSame(['druid', '--site=a', '@b'], Application::rewriteAliasArguments(['druid', '@a', '@b']));
        self::assertSame(['druid', 'x', '--', '@literal'], Application::rewriteAliasArguments(['druid', 'x', '--', '@literal']));
        self::assertSame(['druid', 'up:m', 'foo@bar'], Application::rewriteAliasArguments(['druid', 'up:m', 'foo@bar']));
    }

    public function testRegistersDrushStyleCommandsAndAliases(): void
    {
        $app = new Application(new Services(sys_get_temp_dir() . '/druid-app-test'));
        foreach (['status', 'scan', 'report', 'plan', 'upgrade', 'upgrade:core', 'upgrade:module', 'upgrade:major', 'upgrade:check', 'rollback', 'doctor', 'init', 'site:add', 'site:list', 'site:discover', 'site:remove'] as $name) {
            self::assertTrue($app->has($name), "{$name} is registered");
        }
        self::assertSame('upgrade', $app->find('up')->getName());
        self::assertSame('upgrade:module', $app->find('up:m')->getName());
        self::assertSame('site:list', $app->find('sites')->getName());
        $definition = $app->getDefinition();
        foreach (['root', 'site', 'uri', 'yes', 'simulate'] as $option) {
            self::assertTrue($definition->hasOption($option), "--{$option} is global");
        }
    }

    public function testLauncherDoesNotHandOffToItself(): void
    {
        $script = realpath(__DIR__ . '/../../bin/druid') ?: '';
        self::assertNull(Launcher::handoffTarget(['druid', 'status'], __DIR__, $script));
    }
}
