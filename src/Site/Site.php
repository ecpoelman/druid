<?php

declare(strict_types=1);

namespace Druid\Site;

use Druid\Util\Path;

/**
 * A local Drupal project identified by its Composer root.
 */
final class Site
{
    public function __construct(
        public readonly string $name,
        public readonly string $root,
        public readonly ?string $url = null,
        public readonly string $source = 'manual',
        public readonly ?string $uri = null,
    ) {
    }

    /**
     * Stable file-safe identifier: name plus a short hash of the root.
     */
    public function slug(): string
    {
        return Path::slug($this->name) . '-' . substr(sha1($this->root), 0, 8);
    }

    public function withName(string $name): self
    {
        return new self($name, $this->root, $this->url, $this->source, $this->uri);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'root' => $this->root,
            'url' => $this->url,
            'source' => $this->source,
            'uri' => $this->uri,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            $name,
            (string) ($data['root'] ?? ''),
            isset($data['url']) ? (string) $data['url'] : null,
            (string) ($data['source'] ?? 'manual'),
            isset($data['uri']) ? (string) $data['uri'] : null,
        );
    }
}
