<?php

declare(strict_types=1);

namespace Druid\Drupal;

use Druid\Util\Path;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads Composer metadata from packages.drupal.org (the p2 endpoint Composer itself uses), cached on disk.
 *
 * The drupal/core requirement in this metadata is what Composer resolves against; the release-history
 * feed's core_compatibility is sometimes looser (e.g. `>=9.4` for a module that requires `^9.4 || ^10`).
 */
final class ComposerMetadataClient
{
    public const ENDPOINT = 'https://packages.drupal.org/files/packages/8/p2/%s.json';

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly string $cacheDir,
        private readonly int $ttl = 3600,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create([
            'timeout' => 20,
            'headers' => ['User-Agent' => 'druid (Drupal upgrade CLI)'],
        ]);
    }

    /**
     * The drupal/core constraint of every published version of each package.
     *
     * @param list<string> $packages e.g. ["drupal/color"]
     * @return array<string, array<string, ?string>> package => version => drupal/core constraint (null: none declared)
     */
    public function coreConstraints(array $packages): array
    {
        $results = [];
        $pending = [];
        foreach (array_unique($packages) as $package) {
            if (preg_match('#^drupal/[a-z0-9_]+$#', $package) !== 1) {
                continue;
            }
            $cached = $this->readCache($package);
            if ($cached !== null) {
                $results[$package] = self::parse($package, $cached);
                continue;
            }
            try {
                $pending[$package] = $this->http->request('GET', sprintf(self::ENDPOINT, $package));
            } catch (\Throwable) {
                continue;
            }
        }

        foreach ($pending as $package => $response) {
            try {
                if ($response->getStatusCode() !== 200) {
                    continue;
                }
                $json = $response->getContent();
            } catch (\Throwable) {
                $stale = $this->readCache($package, true);
                if ($stale !== null) {
                    $results[$package] = self::parse($package, $stale);
                }
                continue;
            }
            $this->writeCache($package, $json);
            $results[$package] = self::parse($package, $json);
        }

        return array_filter($results, static fn (array $versions): bool => $versions !== []);
    }

    /**
     * @return array<string, ?string> version => drupal/core constraint
     */
    public static function parse(string $package, string $json): array
    {
        $data = json_decode($json, true);
        $entries = is_array($data) ? ($data['packages'][$package] ?? []) : [];
        if (!is_array($entries)) {
            return [];
        }
        $minified = ($data['minified'] ?? null) === 'composer/2.0';

        $versions = [];
        $previous = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ($minified) {
                // Minified metadata only lists keys that differ from the previous entry; "__unset" removes one.
                $entry = array_merge($previous, $entry);
                $entry = array_filter($entry, static fn ($value): bool => $value !== '__unset');
                $previous = $entry;
            }
            $version = $entry['version'] ?? null;
            if (!is_string($version)) {
                continue;
            }
            $core = $entry['require']['drupal/core'] ?? null;
            $versions[ltrim($version, 'vV')] = is_string($core) ? $core : null;
        }
        return $versions;
    }

    private function cacheFile(string $package): string
    {
        return $this->cacheDir . '/composer/' . str_replace('/', '--', $package) . '.json';
    }

    private function readCache(string $package, bool $allowStale = false): ?string
    {
        $file = $this->cacheFile($package);
        if (!is_file($file) || (!$allowStale && time() - (int) filemtime($file) > $this->ttl)) {
            return null;
        }
        $contents = file_get_contents($file);
        return $contents === false ? null : $contents;
    }

    private function writeCache(string $package, string $json): void
    {
        try {
            Path::writeAtomic($this->cacheFile($package), $json);
        } catch (\RuntimeException) {
            // Cache is an optimisation only.
        }
    }
}
