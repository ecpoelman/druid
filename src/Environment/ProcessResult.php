<?php

declare(strict_types=1);

namespace Druid\Environment;

/**
 * Outcome of an external command.
 */
final class ProcessResult
{
    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Stdout followed by stderr. Composer writes most progress and errors to stderr.
     */
    public function combined(): string
    {
        $out = trim($this->output);
        $err = trim($this->errorOutput);
        if ($out === '') {
            return $err;
        }
        return $err === '' ? $out : $out . "\n" . $err;
    }

    /**
     * Last non-empty lines of the combined output, for compact failure messages.
     *
     * @return list<string>
     */
    public function tail(int $lines = 8): array
    {
        $all = array_values(array_filter(
            array_map('rtrim', explode("\n", $this->combined())),
            static fn (string $line): bool => trim($line) !== '',
        ));
        return array_slice($all, -$lines);
    }
}
