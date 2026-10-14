<?php

declare(strict_types=1);

namespace Druid\Composer;

use Druid\Security\VersionResolver;

/**
 * Read-only view of a project's composer.json and composer.lock (re-read when the files change).
 */
final class ComposerProject
{
    public const CORE_PREFIX = 'drupal/core';

    /** Used when composer.json declares no drupal/core* packages at all. */
    public const DEFAULT_CORE_PACKAGES = [
        'drupal/core-recommended',
        'drupal/core-composer-scaffold',
        'drupal/core-project-message',
    ];

    /** @var array<string, array{hash: string, data: array<string, mixed>}> */
    private array $cache = [];

    public function __construct(public readonly string $root)
    {
    }

    // MARK: - Raw files

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return $this->read('composer.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function lock(): array
    {
        return $this->read('composer.lock');
    }

    public function hasLock(): bool
    {
        return is_file($this->root . '/composer.lock');
    }

    public function lockHash(): ?string
    {
        $file = $this->root . '/composer.lock';
        return is_file($file) ? (md5_file($file) ?: null) : null;
    }

    public function name(): ?string
    {
        $name = $this->json()['name'] ?? null;
        return is_string($name) ? $name : null;
    }

    // MARK: - Requirements

    /**
     * Packages listed in require and require-dev.
     *
     * @return array<string, string> package => constraint
     */
    public function requirements(): array
    {
        $json = $this->json();
        $all = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach ((array) ($json[$section] ?? []) as $package => $constraint) {
                $all[(string) $package] ??= (string) $constraint;
            }
        }
        return $all;
    }

    public function isDirect(string $package): bool
    {
        return array_key_exists($package, $this->requirements());
    }

    public function isDevRequirement(string $package): bool
    {
        return isset(((array) ($this->json()['require-dev'] ?? []))[$package]);
    }

    public function constraint(string $package): ?string
    {
        return $this->requirements()[$package] ?? null;
    }

    /**
     * True when a constraint pins one exact version, e.g. `10.6.9` (no ranges or operators).
     */
    public static function isExactVersionConstraint(string $constraint): bool
    {
        $c = ltrim(trim($constraint), 'vV');
        return $c !== '' && preg_match('/^\d+(\.\d+)*$/', $c) === 1;
    }

    /**
     * Constraint for `$version` that keeps the style already used in composer.json:
     * exact pins stay exact, `~` stays `~`, everything else becomes `^`.
     */
    public function constraintFor(string $package, string $version): string
    {
        $current = $this->constraint($package);
        if ($current !== null && self::isExactVersionConstraint($current)) {
            return $version;
        }
        if ($current !== null && str_starts_with(trim($current), '~')) {
            return '~' . $version;
        }
        return '^' . $version;
    }

    // MARK: - Drupal core

    public static function isCorePackage(string $name): bool
    {
        return str_starts_with($name, self::CORE_PREFIX);
    }

    /**
     * Every drupal/core* package declared in composer.json. They must move together.
     *
     * @return list<string>
     */
    public function pinnedCorePackages(): array
    {
        $packages = array_values(array_filter(
            array_keys($this->requirements()),
            static fn (string $name): bool => self::isCorePackage($name),
        ));
        sort($packages);
        return $packages === [] ? self::DEFAULT_CORE_PACKAGES : $packages;
    }

    /**
     * `package:constraint` arguments moving all core packages to one release.
     *
     * @return list<string>
     */
    public function coreRequireArguments(string $version, bool $exact = true): array
    {
        return array_map(
            fn (string $package): string => $package . ':' . ($exact ? $version : $this->constraintFor($package, $version)),
            $this->pinnedCorePackages(),
        );
    }

    public function hasDirectCorePin(): bool
    {
        return $this->isDirect('drupal/core');
    }

    public function hasExactCorePins(): bool
    {
        foreach ($this->pinnedCorePackages() as $package) {
            $constraint = $this->constraint($package);
            if ($constraint !== null && self::isExactVersionConstraint($constraint)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Installed core version from composer.lock, falling back to the composer.json constraint.
     */
    public function installedCoreVersion(): ?string
    {
        $locked = $this->lockedVersion('drupal/core');
        if ($locked !== null) {
            return $locked;
        }
        foreach (['drupal/core-recommended', 'drupal/core'] as $package) {
            $constraint = $this->constraint($package);
            if ($constraint !== null) {
                return ltrim(trim($constraint), '^~v>=');
            }
        }
        return null;
    }

    // MARK: - Lock file

    /**
     * @return array<string, array{name: string, version: string, type: string, dev: bool, abandoned: bool|string, require: array<string, string>}>
     */
    public function lockPackages(): array
    {
        $lock = $this->lock();
        $packages = [];
        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            foreach ((array) ($lock[$section] ?? []) as $item) {
                if (!is_array($item) || !isset($item['name'], $item['version'])) {
                    continue;
                }
                $name = (string) $item['name'];
                $packages[$name] = [
                    'name' => $name,
                    'version' => ltrim((string) $item['version'], 'v'),
                    'type' => (string) ($item['type'] ?? 'library'),
                    'dev' => $dev,
                    'abandoned' => $item['abandoned'] ?? false,
                    'require' => array_map('strval', array_filter((array) ($item['require'] ?? []), 'is_string')),
                ];
            }
        }
        return $packages;
    }

    /**
     * Locked packages whose requirement on `$package` rules out `$version` - the "another require" that
     * Composer means by "found X but these were not loaded, likely because it conflicts with another require".
     *
     * @return list<string>
     */
    public function lockedRequirersExcluding(string $package, string $version): array
    {
        $requirers = [];
        foreach ($this->lockPackages() as $name => $locked) {
            $constraint = $locked['require'][$package] ?? null;
            if ($constraint !== null && !VersionResolver::satisfies($version, $constraint)) {
                $requirers[] = $name;
            }
        }
        return $requirers;
    }

    public function lockedVersion(string $package): ?string
    {
        return $this->lockPackages()[$package]['version'] ?? null;
    }

    // MARK: - Plugins and extra

    public function hasPackage(string $package): bool
    {
        return $this->isDirect($package) || isset($this->lockPackages()[$package]);
    }

    public function pluginAllowed(string $plugin): bool
    {
        $allow = $this->json()['config']['allow-plugins'] ?? null;
        if ($allow === true) {
            return true;
        }
        return is_array($allow) && ($allow[$plugin] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    public function lenientAllowList(): array
    {
        $list = $this->json()['extra']['drupal-lenient']['allowed-list'] ?? [];
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        $path = $this->root . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $contents = (string) @file_get_contents($path);
        $hash = md5($contents);
        if (isset($this->cache[$file]) && $this->cache[$file]['hash'] === $hash) {
            return $this->cache[$file]['data'];
        }
        $data = json_decode($contents, true);
        $data = is_array($data) ? $data : [];
        $this->cache[$file] = ['hash' => $hash, 'data' => $data];
        return $data;
    }
}
