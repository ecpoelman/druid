<?php

declare(strict_types=1);

namespace Druid\Tests\Support;

/**
 * Creates throwaway Composer projects in the system temp directory.
 */
final class TempProject
{
    /** @var list<string> */
    private static array $created = [];

    /**
     * @param array<string, mixed> $composerJson
     * @param array<string, string> $lockedPackages name => version (optional type via name@type)
     */
    public static function create(array $composerJson, array $lockedPackages = []): string
    {
        $dir = sys_get_temp_dir() . '/druid-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/composer.json', json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($lockedPackages !== []) {
            self::writeLock($dir, $lockedPackages);
        }
        self::$created[] = $dir;
        return $dir;
    }

    /**
     * @param array<string, string> $lockedPackages
     */
    public static function writeLock(string $dir, array $lockedPackages): void
    {
        $packages = [];
        foreach ($lockedPackages as $name => $version) {
            [$package, $type] = array_pad(explode('@', $name, 2), 2, null);
            $packages[] = ['name' => $package, 'version' => $version, 'type' => $type ?? 'library'];
        }
        file_put_contents($dir . '/composer.lock', json_encode(['packages' => $packages, 'packages-dev' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $dir) {
            self::remove($dir);
        }
        self::$created = [];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }
}
