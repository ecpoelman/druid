<?php

declare(strict_types=1);

namespace Druid\Drupal;

use Druid\Security\VersionResolver;

/**
 * Reads and edits `core_version_requirement` in extension .info.yml files, keeping the file's formatting.
 */
final class InfoFile
{
    private const REQUIREMENT_LINE = '/^core_version_requirement:[ \t]*([\'"]?)(.*?)\1[ \t]*$/m';

    /**
     * The .info.yml of a module or theme directory (`<dir>/<name>.info.yml`), if present.
     */
    public static function forExtension(string $name, string $directory): ?string
    {
        $file = rtrim($directory, '/') . '/' . $name . '.info.yml';
        return is_file($file) ? $file : null;
    }

    /**
     * The extension's .info.yml plus those of sub-modules and sub-themes inside it
     * (bundled third-party code and test fixtures are skipped).
     *
     * @return list<string>
     */
    public static function withinExtension(string $name, string $directory): array
    {
        $files = [];
        $main = self::forExtension($name, $directory);
        if ($main !== null) {
            $files[] = $main;
        }
        if (!is_dir($directory)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), ['vendor', 'node_modules', 'tests', 'config', '.git'], true),
        ));
        $nested = [];
        foreach ($iterator as $file) {
            // Machine names have no dots; config such as core.base_field_override.*.info.yml is not an extension.
            if ($file instanceof \SplFileInfo && $file->isFile() && preg_match('/^[a-z0-9_]+\.info\.yml$/', $file->getFilename()) === 1 && $file->getPathname() !== $main) {
                $nested[] = $file->getPathname();
            }
        }
        sort($nested);
        return array_merge($files, $nested);
    }

    public static function requirement(string $file): ?string
    {
        $contents = @file_get_contents($file);
        if ($contents === false || preg_match(self::REQUIREMENT_LINE, $contents, $m) !== 1) {
            return null;
        }
        return trim($m[2]) !== '' ? trim($m[2]) : null;
    }

    /**
     * True when the file's core_version_requirement admits `$coreVersion`.
     */
    public static function supports(string $file, string $coreVersion): bool
    {
        $requirement = self::requirement($file);
        return $requirement !== null && VersionResolver::satisfies($coreVersion, $requirement);
    }

    /**
     * Adds `|| ^$major` to core_version_requirement (or adds the key). Returns the new requirement, or null
     * when nothing changed.
     */
    public static function declareSupport(string $file, int $major, ?int $currentMajor = null): ?string
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }
        $current = self::requirement($file);
        if ($current !== null && VersionResolver::satisfies("{$major}.0.0", $current)) {
            return null;
        }

        if ($current !== null) {
            $updated = "{$current} || ^{$major}";
            $contents = (string) preg_replace_callback(
                self::REQUIREMENT_LINE,
                static fn (array $m): string => 'core_version_requirement: ' . $m[1] . $updated . $m[1],
                $contents,
                1,
            );
        } else {
            $updated = ($currentMajor !== null && $currentMajor < $major ? "^{$currentMajor} || " : '') . "^{$major}";
            $contents = rtrim($contents, "\n") . "\ncore_version_requirement: '{$updated}'\n";
        }
        return file_put_contents($file, $contents) === false ? null : $updated;
    }
}
