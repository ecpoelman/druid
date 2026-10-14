<?php

declare(strict_types=1);

namespace Druid\Environment;

/**
 * Runs tools inside the DDEV web container (`ddev composer`, `ddev drush`).
 * Environment variables are passed with `ddev exec env K=V ...` because host env doesn't reach the container.
 */
final class DdevEnvironment implements ExecutionEnvironment
{
    private const CONTAINER_ROOT = '/var/www/html';

    public function __construct(private readonly string $hostRoot)
    {
    }

    public function name(): string
    {
        return 'ddev';
    }

    public function label(): string
    {
        return 'DDEV';
    }

    public function composer(array $args, array $env = []): CommandLine
    {
        if ($env === []) {
            return new CommandLine(array_merge(['ddev', 'composer'], $args));
        }
        $assignments = [];
        foreach ($env as $key => $value) {
            $assignments[] = "{$key}={$value}";
        }
        return new CommandLine(array_merge(['ddev', 'exec', 'env'], $assignments, ['composer'], $args));
    }

    public function drush(string $binary, array $args): CommandLine
    {
        return new CommandLine(array_merge(['ddev', 'drush'], $args));
    }

    public function php(array $args): CommandLine
    {
        return new CommandLine(array_merge(['ddev', 'exec', 'php'], $args));
    }

    public function containerPath(string $hostPath): string
    {
        $root = rtrim($this->hostRoot, '/');
        if (str_starts_with($hostPath, $root)) {
            return self::CONTAINER_ROOT . substr($hostPath, strlen($root));
        }
        return $hostPath;
    }
}
