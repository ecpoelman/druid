<?php

declare(strict_types=1);

namespace Druid\Upgrade;

/**
 * In-memory copy of the Composer files, taken before each attempt so a failure can be undone.
 */
final class FileSnapshot
{
    public const FILES = ['composer.json', 'composer.lock', 'composer.patches.json', 'patches.lock.json'];

    /**
     * @param array<string, string|null> $contents file => contents (null when the file didn't exist)
     */
    private function __construct(
        private readonly string $root,
        private readonly array $contents,
    ) {
    }

    public static function capture(string $root): self
    {
        $contents = [];
        foreach (self::FILES as $file) {
            $path = $root . '/' . $file;
            $contents[$file] = is_file($path) ? (string) file_get_contents($path) : null;
        }
        return new self($root, $contents);
    }

    public function restore(): void
    {
        foreach ($this->contents as $file => $content) {
            $path = $this->root . '/' . $file;
            if ($content === null) {
                if (is_file($path)) {
                    @unlink($path);
                }
                continue;
            }
            if (!is_file($path) || file_get_contents($path) !== $content) {
                file_put_contents($path, $content);
            }
        }
    }

    public function lockChanged(): bool
    {
        $path = $this->root . '/composer.lock';
        $current = is_file($path) ? (string) file_get_contents($path) : null;
        return $current !== $this->contents['composer.lock'];
    }

    public function composerJsonChanged(): bool
    {
        $path = $this->root . '/composer.json';
        $current = is_file($path) ? (string) file_get_contents($path) : null;
        return $current !== $this->contents['composer.json'];
    }
}
