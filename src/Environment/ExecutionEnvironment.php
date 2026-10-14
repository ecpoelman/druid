<?php

declare(strict_types=1);

namespace Druid\Environment;

/**
 * Where Composer, Drush and PHP run for a site: on the host, or inside DDEV/Lando containers.
 */
interface ExecutionEnvironment
{
    /** Machine name: native, ddev or lando. */
    public function name(): string;

    /** Human label, e.g. "DDEV" or "Native (Herd)". */
    public function label(): string;

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public function composer(array $args, array $env = []): CommandLine;

    /**
     * @param list<string> $args
     */
    public function drush(string $binary, array $args): CommandLine;

    /**
     * @param list<string> $args
     */
    public function php(array $args): CommandLine;

    /** Maps a host path inside the project to the path the tools see. */
    public function containerPath(string $hostPath): string;
}
