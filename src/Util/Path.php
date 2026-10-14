<?php

declare(strict_types=1);

namespace Druid\Util;

/**
 * Filesystem path helpers.
 */
final class Path
{
    public static function home(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }
        $profile = getenv('USERPROFILE');
        return is_string($profile) && $profile !== '' ? rtrim($profile, '\\/') : '/';
    }

    /**
     * Expands a leading `~` to the user's home directory.
     */
    public static function expand(string $path): string
    {
        if ($path === '~') {
            return self::home();
        }
        if (str_starts_with($path, '~/')) {
            return self::home() . substr($path, 1);
        }
        return $path;
    }

    /**
     * Canonical path when it exists, otherwise the expanded input without a trailing slash.
     */
    public static function normalize(string $path): string
    {
        $expanded = self::expand($path);
        $real = realpath($expanded);
        return $real !== false ? $real : rtrim($expanded, '/');
    }

    /**
     * Replaces the home directory prefix with `~` for display.
     */
    public static function shorten(string $path): string
    {
        $home = self::home();
        if ($home !== '/' && str_starts_with($path, $home . '/')) {
            return '~' . substr($path, strlen($home));
        }
        return $path;
    }

    public static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create directory {$dir}");
        }
    }

    /**
     * Writes a file atomically via a temporary file and rename.
     */
    public static function writeAtomic(string $file, string $contents): void
    {
        self::ensureDirectory(dirname($file));
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException("Could not write {$file}");
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not write {$file}");
        }
    }

    public static function slug(string $text): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $text));
        return trim($slug, '-') ?: 'site';
    }
}
