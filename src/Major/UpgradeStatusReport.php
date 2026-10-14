<?php

declare(strict_types=1);

namespace Druid\Major;

/**
 * Findings from the Upgrade Status module.
 */
final class UpgradeStatusReport
{
    /**
     * @param list<array{extension: string, file: string, line: int, severity: string, message: string}> $issues
     */
    public function __construct(
        public readonly array $issues,
        public readonly string $raw = '',
        public readonly bool $structured = true,
    ) {
    }

    /**
     * Issue counts per extension, most issues first.
     *
     * @return array<string, int>
     */
    public function byExtension(): array
    {
        $counts = [];
        foreach ($this->issues as $issue) {
            $counts[$issue['extension']] = ($counts[$issue['extension']] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    public function count(): int
    {
        return count($this->issues);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'structured' => $this->structured,
            'count' => $this->count(),
            'by_extension' => $this->byExtension(),
            'issues' => $this->issues,
        ];
    }
}
