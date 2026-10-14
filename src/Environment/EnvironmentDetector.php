<?php

declare(strict_types=1);

namespace Druid\Environment;

use Druid\Config\Config;
use Druid\Util\Path;

/**
 * Chooses the execution environment for a project: config override first, then DDEV/Lando markers.
 */
final class EnvironmentDetector
{
    public static function detect(string $root, Config $config, ProcessRunner $runner): ExecutionEnvironment
    {
        $choice = strtolower((string) $config->get('environment', 'auto'));

        return match ($choice) {
            'native' => self::native($runner),
            'ddev' => new DdevEnvironment($root),
            'lando' => new LandoEnvironment($root),
            default => self::auto($root, $runner),
        };
    }

    private static function auto(string $root, ProcessRunner $runner): ExecutionEnvironment
    {
        if (is_file($root . '/.ddev/config.yaml') && $runner->has('ddev')) {
            return new DdevEnvironment($root);
        }
        if (is_file($root . '/.lando.yml') && $runner->has('lando')) {
            return new LandoEnvironment($root);
        }
        return self::native($runner);
    }

    private static function native(ProcessRunner $runner): NativeEnvironment
    {
        $php = $runner->resolveExecutable('php') ?? '';
        if (str_contains($php, 'Herd')) {
            return new NativeEnvironment('Native (Herd)');
        }
        if (str_starts_with($php, '/opt/homebrew') || str_starts_with($php, '/usr/local')) {
            return new NativeEnvironment('Native (Homebrew)');
        }
        return new NativeEnvironment($php === '' ? 'Native' : 'Native (' . Path::shorten(dirname($php)) . ')');
    }
}
