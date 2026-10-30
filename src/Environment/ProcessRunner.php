<?php

declare(strict_types=1);

namespace Druid\Environment;

use Druid\Util\Path;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs external commands with a PATH that also covers Herd, Homebrew and Composer global bins,
 * so tools resolve even when druid runs from a minimal environment (cron, launchd, IDEs).
 */
class ProcessRunner
{
    /** @var array<string, string|null> */
    private array $resolved = [];

    /** Wrapper directory once prepared; '' when unavailable. */
    private static ?string $shims = null;

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @param (callable(string $buffer, bool $isError): void)|null $onOutput
     */
    public function run(array $command, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = 900.0): ProcessResult
    {
        if ($command === []) {
            throw new \InvalidArgumentException('Cannot run an empty command.');
        }

        $command[0] = $this->resolveExecutable($command[0]) ?? $command[0];
        $display = CommandLine::format($command, $env);
        $process = new Process($this->detached($command), $cwd, $this->environment($env), null, $timeout);

        try {
            $process->run(static function (string $type, string $buffer) use ($onOutput): void {
                if ($onOutput !== null) {
                    $onOutput($buffer, $type === Process::ERR);
                }
            });
        } catch (ProcessTimedOutException) {
            return new ProcessResult($display, 124, $process->getOutput(), $process->getErrorOutput() . "\nTimed out after {$timeout} seconds.");
        } catch (\Throwable $e) {
            return new ProcessResult($display, 127, '', $e->getMessage());
        }

        return new ProcessResult($display, $process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * Runs read-only commands concurrently and waits for all of them.
     *
     * @param array<string, array{command: list<string>, cwd?: ?string, env?: array<string, string>, timeout?: ?float}> $jobs
     * @return array<string, ProcessResult> keyed like `$jobs`
     */
    public function runParallel(array $jobs): array
    {
        $processes = [];
        $results = [];
        foreach ($jobs as $key => $job) {
            $command = $job['command'];
            $command[0] = $this->resolveExecutable($command[0]) ?? $command[0];
            $env = $job['env'] ?? [];
            $process = new Process($this->detached($command), $job['cwd'] ?? null, $this->environment($env), null, $job['timeout'] ?? 900.0);
            try {
                $process->start();
                $processes[$key] = [$process, CommandLine::format($command, $env)];
            } catch (\Throwable $e) {
                $results[$key] = new ProcessResult(CommandLine::format($command, $env), 127, '', $e->getMessage());
            }
        }
        foreach ($processes as $key => [$process, $display]) {
            try {
                $process->wait();
                $results[$key] = new ProcessResult($display, $process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
            } catch (ProcessTimedOutException) {
                $results[$key] = new ProcessResult($display, 124, $process->getOutput(), $process->getErrorOutput() . "\nTimed out.");
            } catch (\Throwable $e) {
                $results[$key] = new ProcessResult($display, 127, '', $e->getMessage());
            }
        }
        $ordered = [];
        foreach (array_keys($jobs) as $key) {
            $ordered[$key] = $results[$key];
        }
        return $ordered;
    }

    /**
     * Full path to an executable, or null when it can't be found.
     */
    public function resolveExecutable(string $name): ?string
    {
        if (str_contains($name, '/')) {
            return is_file($name) ? $name : null;
        }
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }
        foreach (self::pathDirectories() as $dir) {
            $candidate = $dir . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $this->resolved[$name] = $candidate;
            }
        }
        return $this->resolved[$name] = null;
    }

    public function has(string $name): bool
    {
        return $this->resolveExecutable($name) !== null;
    }

    /**
     * Directories searched for executables, in priority order.
     *
     * @return list<string>
     */
    public static function pathDirectories(): array
    {
        $home = Path::home();
        $dirs = [
            $home . '/Library/Application Support/Herd/bin',
            '/opt/homebrew/bin',
            '/usr/local/bin',
            $home . '/.composer/vendor/bin',
            $home . '/.config/composer/vendor/bin',
            $home . '/.local/bin',
            $home . '/bin',
        ];
        $path = getenv('PATH');
        if (is_string($path)) {
            foreach (explode(PATH_SEPARATOR, $path) as $segment) {
                if ($segment !== '') {
                    $dirs[] = $segment;
                }
            }
        }
        array_push($dirs, '/usr/bin', '/bin', '/usr/sbin', '/sbin');
        return array_values(array_unique($dirs));
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function environment(array $extra): array
    {
        $dirs = self::pathDirectories();
        $shims = $this->shimDirectory();
        if ($shims !== null) {
            array_unshift($dirs, $shims);
        }
        return ['PATH' => implode(PATH_SEPARATOR, $dirs)] + $extra;
    }

    /**
     * Runs the command in its own session, without a controlling terminal. Tools that ask questions on
     * /dev/tty (patch, git credential prompts) then fail instead of waiting for an answer nobody sees.
     *
     * @param list<string> $command
     * @return list<string>
     */
    private function detached(array $command): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $command;
        }
        $perl = $this->resolveExecutable('perl');
        if ($perl === null) {
            return $command;
        }
        return [$perl, '-MPOSIX', '-e', 'POSIX::setsid(); exec { $ARGV[0] } @ARGV; print STDERR "$ARGV[0]: $!\n"; exit 127', '--', ...$command];
    }

    /**
     * Directory with a `patch` wrapper that adds --forward. Without a terminal, patch answers its
     * "previously applied patch - assume -R?" question with yes and silently reverts the fix
     * (composer-patches can't pass flags). With --forward it skips the patch and reports a failure.
     */
    private function shimDirectory(): ?string
    {
        if (self::$shims !== null) {
            return self::$shims === '' ? null : self::$shims;
        }
        self::$shims = '';
        $patch = PHP_OS_FAMILY === 'Windows' ? null : $this->resolveExecutable('patch');
        if ($patch === null) {
            return null;
        }
        $dir = sys_get_temp_dir() . '/druid-shims-' . (function_exists('posix_getuid') ? posix_getuid() : get_current_user());
        $file = $dir . '/patch';
        $script = "#!/bin/sh\n# druid: never reverse a patch that looks already applied.\nexec " . escapeshellarg($patch) . " --forward \"\$@\"\n";
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return null;
        }
        if (@file_get_contents($file) !== $script && @file_put_contents($file, $script) === false) {
            return null;
        }
        if (!@chmod($file, 0755)) {
            return null;
        }
        return self::$shims = $dir;
    }
}
