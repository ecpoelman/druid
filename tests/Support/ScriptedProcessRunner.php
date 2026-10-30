<?php

declare(strict_types=1);

namespace Druid\Tests\Support;

use Druid\Environment\ProcessResult;
use Druid\Environment\ProcessRunner;

/**
 * ProcessRunner that answers from scripted handlers instead of running anything.
 * Each handler receives the command and env and returns a result, or null to pass.
 */
final class ScriptedProcessRunner extends ProcessRunner
{
    /** @var list<array{command: list<string>, env: array<string, string>}> */
    public array $calls = [];

    /** @var list<callable(list<string>, array<string, string>): ?ProcessResult> */
    private array $handlers = [];

    /**
     * @param callable(list<string>, array<string, string>): ?ProcessResult $handler
     */
    public function on(callable $handler): self
    {
        $this->handlers[] = $handler;
        return $this;
    }

    public function run(array $command, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = 900.0): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'env' => $env];
        foreach ($this->handlers as $handler) {
            $result = $handler($command, $env);
            if ($result !== null) {
                return $result;
            }
        }
        return self::fail(implode(' ', $command), 'No scripted response');
    }

    public function runParallel(array $jobs): array
    {
        $results = [];
        foreach ($jobs as $key => $job) {
            $results[$key] = $this->run($job['command'], $job['cwd'] ?? null, $job['env'] ?? []);
        }
        return $results;
    }

    public function resolveExecutable(string $name): ?string
    {
        return $name;
    }

    /**
     * Composer commands run so far, as strings without the trailing --no-interaction --no-ansi.
     *
     * @return list<string>
     */
    public function composerCalls(): array
    {
        $calls = [];
        foreach ($this->calls as $call) {
            if (($call['command'][0] ?? '') !== 'composer') {
                continue;
            }
            $args = array_values(array_diff(array_slice($call['command'], 1), ['--no-interaction', '--no-ansi']));
            $calls[] = implode(' ', $args);
        }
        return $calls;
    }

    public static function ok(string $command = 'cmd', string $output = ''): ProcessResult
    {
        return new ProcessResult($command, 0, $output, '');
    }

    public static function fail(string $command = 'cmd', string $error = 'failed', int $code = 1): ProcessResult
    {
        return new ProcessResult($command, $code, '', $error);
    }
}
