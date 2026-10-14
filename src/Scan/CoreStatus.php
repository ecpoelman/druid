<?php

declare(strict_types=1);

namespace Druid\Scan;

use Druid\Security\Advisory;
use Druid\Security\Severity;

/**
 * Drupal core version, available targets and open advisories.
 */
final class CoreStatus
{
    /**
     * @param list<Advisory> $advisories advisories affecting the installed version
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $current,
        public readonly ?string $securityTarget = null,
        public readonly ?string $latestPatch = null,
        public readonly ?string $latestMinor = null,
        public readonly ?string $nextMajor = null,
        public readonly array $advisories = [],
        public readonly bool $supported = true,
        public readonly array $notes = [],
    ) {
    }

    public function severity(): Severity
    {
        return Severity::highest(array_map(static fn (Advisory $a): Severity => $a->severity, $this->advisories));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'security_target' => $this->securityTarget,
            'latest_patch' => $this->latestPatch,
            'latest_minor' => $this->latestMinor,
            'next_major' => $this->nextMajor,
            'supported' => $this->supported,
            'advisories' => array_map(static fn (Advisory $a): array => $a->toArray(), $this->advisories),
            'notes' => $this->notes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['current'] ?? '?'),
            self::nullable($data['security_target'] ?? null),
            self::nullable($data['latest_patch'] ?? null),
            self::nullable($data['latest_minor'] ?? null),
            self::nullable($data['next_major'] ?? null),
            array_map([Advisory::class, 'fromArray'], (array) ($data['advisories'] ?? [])),
            (bool) ($data['supported'] ?? true),
            array_values(array_map('strval', (array) ($data['notes'] ?? []))),
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
