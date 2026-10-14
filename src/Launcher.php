<?php

declare(strict_types=1);

namespace Druid;

use Druid\Site\DrupalProjectDetector;

/**
 * Hands a global `druid` invocation off to the copy installed in the current project,
 * the same way the Drush launcher prefers a site-local Drush.
 */
final class Launcher
{
    public const ENV_FLAG = 'DRUID_LAUNCHED';

    /**
     * Returns the project-local druid script to run instead of this one, or null to continue.
     *
     * @param list<string> $argv
     */
    public static function handoffTarget(array $argv, string $cwd, string $currentScript): ?string
    {
        if (getenv(self::ENV_FLAG) !== false || getenv('DRUID_NO_HANDOFF') !== false) {
            return null;
        }
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '@') || $arg === '-r' || str_starts_with($arg, '--root') || str_starts_with($arg, '--site')) {
                return null;
            }
        }

        $root = DrupalProjectDetector::findComposerRoot($cwd);
        if ($root === null) {
            return null;
        }

        $local = DrupalProjectDetector::vendorDir($root) . '/druid/druid/bin/druid';
        if (!is_file($local)) {
            return null;
        }

        $localReal = realpath($local);
        $currentReal = realpath($currentScript);
        if ($localReal === false || $localReal === $currentReal) {
            return null;
        }

        return $localReal;
    }

    /**
     * Runs the project-local druid with the same arguments and returns its exit code.
     *
     * @param list<string> $argv
     */
    public static function execute(string $script, array $argv): int
    {
        $command = array_merge([PHP_BINARY, $script], array_slice($argv, 1));
        $env = getenv();
        $env[self::ENV_FLAG] = '1';

        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $env);
        if (!is_resource($process)) {
            fwrite(STDERR, "Could not start project-local druid at {$script}.\n");
            return 1;
        }

        return proc_close($process);
    }
}
