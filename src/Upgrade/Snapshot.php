<?php

declare(strict_types=1);

namespace Druid\Upgrade;

/**
 * A saved copy of a site's Composer files (and optionally a database dump) taken before an upgrade run.
 */
final class Snapshot
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        private array $meta,
    ) {
    }

    public function label(): string
    {
        return (string) ($this->meta['label'] ?? '');
    }

    public function createdAt(): string
    {
        return (string) ($this->meta['created_at'] ?? '');
    }

    public function coreVersion(): ?string
    {
        return isset($this->meta['core_version']) ? (string) $this->meta['core_version'] : null;
    }

    public function databaseDump(): ?string
    {
        $dump = $this->meta['db_dump'] ?? null;
        return is_string($dump) && is_file($dump) ? $dump : null;
    }

    public function setDatabaseDump(string $file): void
    {
        $this->meta['db_dump'] = $file;
        $this->saveMeta();
    }

    /**
     * @param array<string, mixed> $result
     */
    public function recordResult(array $result): void
    {
        $this->meta['result'] = $result;
        $this->saveMeta();
    }

    /**
     * Copies the saved Composer files back into the project.
     */
    public function restoreFiles(string $root): void
    {
        foreach (FileSnapshot::FILES as $file) {
            $saved = $this->path . '/' . $file;
            $target = $root . '/' . $file;
            if (is_file($saved)) {
                copy($saved, $target);
            } elseif ($file !== 'composer.json' && is_file($target)) {
                // The file didn't exist when the snapshot was taken.
                @unlink($target);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    private function saveMeta(): void
    {
        file_put_contents($this->path . '/meta.json', (string) json_encode($this->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
