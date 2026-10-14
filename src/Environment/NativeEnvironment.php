<?php

declare(strict_types=1);

namespace Druid\Environment;

/**
 * Runs tools directly on the host (Herd, Homebrew, MAMP, Linux packages).
 */
final class NativeEnvironment implements ExecutionEnvironment
{
    public function __construct(private readonly string $label = 'Native')
    {
    }

    public function name(): string
    {
        return 'native';
    }

    public function label(): string
    {
        return $this->label;
    }

    public function composer(array $args, array $env = []): CommandLine
    {
        return new CommandLine(array_merge(['composer'], $args), $env);
    }

    public function drush(string $binary, array $args): CommandLine
    {
        return new CommandLine(array_merge([$binary], $args));
    }

    public function php(array $args): CommandLine
    {
        return new CommandLine(array_merge(['php'], $args));
    }

    public function containerPath(string $hostPath): string
    {
        return $hostPath;
    }
}
