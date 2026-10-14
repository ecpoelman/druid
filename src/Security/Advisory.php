<?php

declare(strict_types=1);

namespace Druid\Security;

/**
 * A security advisory affecting one package.
 */
final class Advisory
{
    public function __construct(
        public readonly string $id,
        public readonly string $package,
        public readonly string $title,
        public readonly Severity $severity,
        public readonly ?string $affectedVersions = null,
        public readonly ?string $cve = null,
        public readonly ?string $link = null,
        /** composer | drush */
        public readonly string $source = 'composer',
    ) {
    }

    /**
     * Builds an advisory from one `composer audit --format=json` entry.
     *
     * @param array<string, mixed> $item
     */
    public static function fromComposerAudit(string $package, array $item): self
    {
        $title = (string) ($item['title'] ?? $package);
        return new self(
            (string) ($item['advisoryId'] ?? $item['cve'] ?? $title),
            $package,
            $title,
            Severity::fromAdvisory(isset($item['severity']) ? (string) $item['severity'] : null, $title),
            isset($item['affectedVersions']) ? (string) $item['affectedVersions'] : null,
            isset($item['cve']) && $item['cve'] !== '' ? (string) $item['cve'] : null,
            isset($item['link']) ? (string) $item['link'] : null,
        );
    }

    public function affects(string $version): bool
    {
        return $this->affectedVersions !== null && VersionResolver::isAffected($version, $this->affectedVersions);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'package' => $this->package,
            'title' => $this->title,
            'severity' => $this->severity->value,
            'affected_versions' => $this->affectedVersions,
            'cve' => $this->cve,
            'link' => $this->link,
            'source' => $this->source,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['package'] ?? ''),
            (string) ($data['title'] ?? ''),
            Severity::tryFrom((string) ($data['severity'] ?? '')) ?? Severity::Unknown,
            isset($data['affected_versions']) ? (string) $data['affected_versions'] : null,
            isset($data['cve']) ? (string) $data['cve'] : null,
            isset($data['link']) ? (string) $data['link'] : null,
            (string) ($data['source'] ?? 'composer'),
        );
    }
}
