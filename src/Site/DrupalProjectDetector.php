<?php

declare(strict_types=1);

namespace Druid\Site;

/**
 * Recognises Composer-managed Drupal projects on disk.
 */
final class DrupalProjectDetector
{
    private const CORE_KEYS = ['drupal/core-recommended', 'drupal/core', 'drupal/core-dev'];

    /**
     * True when composer.json at `$dir` requires Drupal core.
     */
    public static function isDrupalComposerRoot(string $dir): bool
    {
        $json = self::readJson($dir . '/composer.json');
        if ($json === null) {
            return false;
        }
        foreach (['require', 'require-dev'] as $section) {
            $require = $json[$section] ?? null;
            if (!is_array($require)) {
                continue;
            }
            foreach (self::CORE_KEYS as $key) {
                if (isset($require[$key])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Walks up from `$start` to the nearest Drupal Composer root, optionally not above `$boundary`.
     */
    public static function findComposerRoot(string $start, ?string $boundary = null): ?string
    {
        $dir = realpath($start);
        if ($dir === false) {
            return null;
        }
        if (is_file($dir)) {
            $dir = dirname($dir);
        }
        $boundaryReal = $boundary !== null ? realpath($boundary) : false;

        while (true) {
            if (self::isDrupalComposerRoot($dir)) {
                return $dir;
            }
            if ($boundaryReal !== false && $dir === $boundaryReal) {
                return null;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /**
     * Drupal docroot, from drupal-scaffold config or common folder names.
     */
    public static function docroot(string $root): string
    {
        $json = self::readJson($root . '/composer.json') ?? [];
        $webRoot = $json['extra']['drupal-scaffold']['locations']['web-root'] ?? null;
        if (is_string($webRoot) && $webRoot !== '') {
            $candidate = rtrim($root . '/' . trim($webRoot, '/'), '/.');
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }
        foreach (['web', 'docroot', 'public', 'html'] as $folder) {
            if (is_file("{$root}/{$folder}/core/lib/Drupal.php") || is_file("{$root}/{$folder}/index.php")) {
                return "{$root}/{$folder}";
            }
        }
        return $root;
    }

    public static function vendorDir(string $root): string
    {
        $json = self::readJson($root . '/composer.json') ?? [];
        $vendor = $json['config']['vendor-dir'] ?? 'vendor';
        return $root . '/' . trim(is_string($vendor) ? $vendor : 'vendor', '/');
    }

    public static function binDir(string $root): string
    {
        $json = self::readJson($root . '/composer.json') ?? [];
        $bin = $json['config']['bin-dir'] ?? null;
        if (is_string($bin) && $bin !== '') {
            return $root . '/' . trim($bin, '/');
        }
        return self::vendorDir($root) . '/bin';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }
        $data = json_decode($contents, true);
        return is_array($data) ? $data : null;
    }
}
