<?php

declare(strict_types=1);

namespace Druid\Scan;

use Druid\Security\Advisory;
use Druid\Security\Severity;
use Druid\Security\VersionResolver;

/**
 * Update status for one Composer package (contrib module, theme, or library).
 */
final class PackageStatus
{
    public const SECURITY = 'security';
    public const UNSUPPORTED = 'unsupported';
    public const MINOR = 'minor';
    public const MAJOR = 'major';
    public const OK = 'ok';

    /**
     * @param list<Advisory> $advisories
     */
    public function __construct(
        public readonly string $package,
        /** drupal-module | drupal-theme | drupal-profile | drupal-library | library | ... */
        public readonly string $type,
        public readonly bool $direct,
        public readonly bool $dev,
        public readonly string $current,
        public readonly ?string $securityTarget = null,
        public readonly ?string $latestMinor = null,
        public readonly ?string $latestMajor = null,
        public readonly array $advisories = [],
        /** drupal.org project status: published | unsupported | insecure */
        public readonly ?string $projectStatus = null,
        public readonly bool $branchSupported = true,
        public readonly ?bool $enabled = null,
        public readonly bool $abandoned = false,
        public readonly ?string $replacement = null,
    ) {
    }

    public function name(): string
    {
        return str_starts_with($this->package, 'drupal/') ? substr($this->package, 7) : $this->package;
    }

    public function isDrupal(): bool
    {
        return str_starts_with($this->type, 'drupal-') || str_starts_with($this->package, 'drupal/');
    }

    public function category(): string
    {
        if ($this->advisories !== []) {
            return self::SECURITY;
        }
        if ($this->isUnsupported()) {
            return self::UNSUPPORTED;
        }
        if ($this->hasMinorUpdate()) {
            return self::MINOR;
        }
        if ($this->hasMajorUpdate()) {
            return self::MAJOR;
        }
        return self::OK;
    }

    public function isUnsupported(): bool
    {
        return $this->abandoned
            || in_array($this->projectStatus, ['unsupported', 'insecure', 'revoked'], true)
            || !$this->branchSupported;
    }

    public function hasMinorUpdate(): bool
    {
        return $this->latestMinor !== null && VersionResolver::greaterThan($this->latestMinor, $this->current);
    }

    public function hasMajorUpdate(): bool
    {
        return $this->latestMajor !== null
            && VersionResolver::greaterThan($this->latestMajor, $this->current)
            && !VersionResolver::sameMajor($this->latestMajor, $this->current);
    }

    public function severity(): Severity
    {
        return Severity::highest(array_map(static fn (Advisory $a): Severity => $a->severity, $this->advisories));
    }

    /**
     * @return list<string>
     */
    public function affectedRanges(): array
    {
        return array_values(array_filter(array_map(static fn (Advisory $a): ?string => $a->affectedVersions, $this->advisories)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'type' => $this->type,
            'direct' => $this->direct,
            'dev' => $this->dev,
            'current' => $this->current,
            'security_target' => $this->securityTarget,
            'latest_minor' => $this->latestMinor,
            'latest_major' => $this->latestMajor,
            'category' => $this->category(),
            'severity' => $this->severity()->value,
            'advisories' => array_map(static fn (Advisory $a): array => $a->toArray(), $this->advisories),
            'project_status' => $this->projectStatus,
            'branch_supported' => $this->branchSupported,
            'enabled' => $this->enabled,
            'abandoned' => $this->abandoned,
            'replacement' => $this->replacement,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $str = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;
        return new self(
            (string) ($data['package'] ?? ''),
            (string) ($data['type'] ?? 'library'),
            (bool) ($data['direct'] ?? false),
            (bool) ($data['dev'] ?? false),
            (string) ($data['current'] ?? '?'),
            $str($data['security_target'] ?? null),
            $str($data['latest_minor'] ?? null),
            $str($data['latest_major'] ?? null),
            array_map([Advisory::class, 'fromArray'], (array) ($data['advisories'] ?? [])),
            $str($data['project_status'] ?? null),
            (bool) ($data['branch_supported'] ?? true),
            isset($data['enabled']) ? (bool) $data['enabled'] : null,
            (bool) ($data['abandoned'] ?? false),
            $str($data['replacement'] ?? null),
        );
    }
}
