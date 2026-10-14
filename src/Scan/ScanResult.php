<?php

declare(strict_types=1);

namespace Druid\Scan;

use Druid\Security\Severity;

/**
 * Everything druid learned about a site in one scan.
 */
final class ScanResult
{
    /**
     * @param list<PackageStatus> $packages
     * @param array<string, string> $customModules name => path
     * @param array<string, string|bool|null> $environment php, composer, drush, environment, git_branch, git_clean, bootstrap
     * @param list<string> $warnings
     * @param list<string>|null $enabledExtensions machine names installed on the site (null: Drupal not reachable)
     * @param list<string> $missingExtensions installed on the site but their code is gone from the codebase
     */
    public function __construct(
        public readonly string $site,
        public readonly string $root,
        public readonly string $scannedAt,
        public readonly CoreStatus $core,
        public readonly array $packages,
        public readonly array $customModules = [],
        public readonly array $environment = [],
        public readonly array $warnings = [],
        public readonly ?array $enabledExtensions = null,
        public readonly array $missingExtensions = [],
    ) {
    }

    /**
     * @return list<PackageStatus>
     */
    public function byCategory(string $category): array
    {
        return array_values(array_filter($this->packages, static fn (PackageStatus $p): bool => $p->category() === $category));
    }

    /**
     * Packages with a newer release in their current major (security items included).
     *
     * @return list<PackageStatus>
     */
    public function withMinorUpdates(): array
    {
        return array_values(array_filter($this->packages, static fn (PackageStatus $p): bool => $p->hasMinorUpdate()));
    }

    /**
     * @return list<PackageStatus>
     */
    public function withMajorUpdates(): array
    {
        return array_values(array_filter($this->packages, static fn (PackageStatus $p): bool => $p->hasMajorUpdate()));
    }

    public function package(string $package): ?PackageStatus
    {
        foreach ($this->packages as $status) {
            if ($status->package === $package) {
                return $status;
            }
        }
        return null;
    }

    /**
     * Counts for overviews. `security` includes core when it has an open advisory.
     *
     * @return array{security: int, unsupported: int, minor: int, major: int, severity: Severity, core_security: bool}
     */
    public function summary(): array
    {
        $security = $this->byCategory(PackageStatus::SECURITY);
        $coreSecurity = $this->core->securityTarget !== null || $this->core->advisories !== [];
        $severities = array_map(static fn (PackageStatus $p): Severity => $p->severity(), $security);
        if ($coreSecurity) {
            $severities[] = $this->core->severity();
        }
        return [
            'security' => count($security) + ($coreSecurity ? 1 : 0),
            'unsupported' => count($this->byCategory(PackageStatus::UNSUPPORTED)),
            'minor' => count($this->byCategory(PackageStatus::MINOR)),
            'major' => count($this->withMajorUpdates()),
            'severity' => Severity::highest($severities),
            'core_security' => $coreSecurity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryArray(): array
    {
        $summary = $this->summary();
        $summary['severity'] = $summary['severity']->value;
        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site' => $this->site,
            'root' => $this->root,
            'scanned_at' => $this->scannedAt,
            'summary' => $this->summaryArray(),
            'core' => $this->core->toArray(),
            'packages' => array_map(static fn (PackageStatus $p): array => $p->toArray(), $this->packages),
            'custom_modules' => $this->customModules,
            'environment' => $this->environment,
            'warnings' => $this->warnings,
            'enabled_extensions' => $this->enabledExtensions,
            'missing_extensions' => $this->missingExtensions,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['site'] ?? ''),
            (string) ($data['root'] ?? ''),
            (string) ($data['scanned_at'] ?? ''),
            CoreStatus::fromArray((array) ($data['core'] ?? [])),
            array_map([PackageStatus::class, 'fromArray'], array_values((array) ($data['packages'] ?? []))),
            array_map('strval', (array) ($data['custom_modules'] ?? [])),
            (array) ($data['environment'] ?? []),
            array_values(array_map('strval', (array) ($data['warnings'] ?? []))),
            isset($data['enabled_extensions']) ? array_values(array_map('strval', (array) $data['enabled_extensions'])) : null,
            array_values(array_map('strval', (array) ($data['missing_extensions'] ?? []))),
        );
    }
}
