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
        $process = new Process($command, $cwd, $this->environment($env), null, $timeout);

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
        return ['PATH' => implode(PATH_SEPARATOR, self::pathDirectories())] + $extra;
    }
}
