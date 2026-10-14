<?php

declare(strict_types=1);

namespace Druid\Environment;

/**
 * A command plus the host environment variables it runs with.
 */
final class CommandLine
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    public function __construct(
        public readonly array $command,
        public readonly array $env = [],
    ) {
    }

    public function display(): string
    {
        return self::format($this->command, $this->env);
    }

    /**
     * Shell-style rendering, e.g. `COMPOSER_NO_BLOCKING=1 composer update drupal/token`.
     *
     * @param list<string> $command
     * @param array<string, string> $env
     */
    public static function format(array $command, array $env = []): string
    {
        $parts = [];
        foreach ($env as $key => $value) {
            $parts[] = $key . '=' . self::quote($value);
        }
        foreach ($command as $index => $arg) {
            $parts[] = $index === 0 ? basename($arg) : self::quote($arg);
        }
        return implode(' ', $parts);
    }

    public static function quote(string $arg): string
    {
        if ($arg !== '' && preg_match('/^[A-Za-z0-9_\-.,:\/=@^~*+%]+$/', $arg) === 1) {
            return $arg;
        }
        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }
}
