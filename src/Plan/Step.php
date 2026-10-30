<?php

declare(strict_types=1);

namespace Druid\Plan;

use Druid\Security\Severity;

/**
 * One unit of work in an upgrade plan, e.g. "Drupal core 10.6.9 to 10.6.11" or "token 1.13.0 to 1.15.0".
 */
final class Step
{
    public const CORE = 'core';
    public const MODULE = 'module';
    public const LIBRARY = 'library';
    public const MAJOR_CORE = 'major-core';
    public const LENIENT = 'lenient';
    public const READINESS = 'readiness';
    public const RECTOR = 'rector';
    public const INFO_FILES = 'info-files';
    public const PHP_PLATFORM = 'php-platform';

    public const SCOPE_SECURITY = 'security';
    public const SCOPE_MINOR = 'minor';
    public const SCOPE_MAJOR = 'major';

    /**
     * @param list<string> $affected advisory ranges the target must stay out of
     * @param array<string, string> $companions package => constraint moved in the same Composer run
     * @param list<string> $packages packages a lenient step allows
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $scope,
        public readonly string $title,
        public readonly ?string $package = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly Severity $severity = Severity::None,
        /** low | medium | high */
        public readonly string $risk = 'low',
        public readonly string $description = '',
        public readonly bool $critical = false,
        public readonly array $affected = [],
        public readonly array $companions = [],
        public readonly bool $direct = true,
        public readonly array $packages = [],
    ) {
    }

    /** Steps executed through Composer and the strategy ladder. */
    public function isComposerStep(): bool
    {
        return in_array($this->kind, [self::CORE, self::MODULE, self::LIBRARY, self::MAJOR_CORE], true);
    }

    public function isCore(): bool
    {
        return $this->kind === self::CORE || $this->kind === self::MAJOR_CORE;
    }

    public function isSecurity(): bool
    {
        return $this->scope === self::SCOPE_SECURITY;
    }

    public function withTarget(string $to): self
    {
        return new self($this->id, $this->kind, $this->scope, $this->title, $this->package, $this->from, $to, $this->severity, $this->risk, $this->description, $this->critical, $this->affected, $this->companions, $this->direct, $this->packages);
    }

    /**
     * @param array<string, string> $companions added to (and overriding) the existing companions
     */
    public function withCompanions(array $companions): self
    {
        return new self($this->id, $this->kind, $this->scope, $this->title, $this->package, $this->from, $this->to, $this->severity, $this->risk, $this->description, $this->critical, $this->affected, $companions + $this->companions, $this->direct, $this->packages);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'scope' => $this->scope,
            'title' => $this->title,
            'package' => $this->package,
            'from' => $this->from,
            'to' => $this->to,
            'severity' => $this->severity->value,
            'risk' => $this->risk,
            'description' => $this->description,
            'critical' => $this->critical,
            'companions' => $this->companions,
            'packages' => $this->packages,
        ];
    }
}
