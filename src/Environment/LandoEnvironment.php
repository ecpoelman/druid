<?php

declare(strict_types=1);

namespace Druid\Environment;

/**
 * Runs tools inside Lando (`lando composer`, `lando drush`).
 */
final class LandoEnvironment implements ExecutionEnvironment
{
    private const CONTAINER_ROOT = '/app';

    public function __construct(private readonly string $hostRoot)
    {
    }

    public function name(): string
    {
        return 'lando';
    }

    public function label(): string
    {
        return 'Lando';
    }

    public function composer(array $args, array $env = []): CommandLine
    {
        if ($env === []) {
            return new CommandLine(array_merge(['lando', 'composer'], $args));
        }
        $assignments = [];
        foreach ($env as $key => $value) {
            $assignments[] = $key . '=' . CommandLine::quote($value);
        }
        $quoted = array_map([CommandLine::class, 'quote'], $args);
        $script = 'env ' . implode(' ', $assignments) . ' composer ' . implode(' ', $quoted);
        return new CommandLine(['lando', 'ssh', '-c', $script]);
    }

    public function drush(string $binary, array $args): CommandLine
    {
        return new CommandLine(array_merge(['lando', 'drush'], $args));
    }

    public function php(array $args): CommandLine
    {
        return new CommandLine(array_merge(['lando', 'php'], $args));
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
