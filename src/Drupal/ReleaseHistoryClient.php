<?php

declare(strict_types=1);

namespace Druid\Drupal;

use Druid\Util\Path;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches https://updates.drupal.org/release-history/{project}/current with an on-disk cache.
 * Requests for several projects run concurrently.
 */
final class ReleaseHistoryClient
{
    public const ENDPOINT = 'https://updates.drupal.org/release-history/%s/current';

    private readonly HttpClientInterface $http;

    /** @var list<string> */
    private array $errors = [];

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

    public function fetch(string $project, bool $refresh = false): ?ReleaseHistory
    {
        return $this->fetchMany([$project], $refresh)[$project] ?? null;
    }

    /**
     * @param list<string> $projects
     * @return array<string, ReleaseHistory> projects without history (custom, removed, offline) are omitted
     */
    public function fetchMany(array $projects, bool $refresh = false): array
    {
        $this->errors = [];
        $results = [];
        $pending = [];

        foreach (array_unique($projects) as $project) {
            if (preg_match('/^[a-z0-9_]+$/', $project) !== 1) {
                continue;
            }
            $cached = $refresh ? null : $this->readCache($project);
            if ($cached !== null) {
                $history = ReleaseHistory::fromXml($project, $cached);
                if ($history !== null) {
                    $results[$project] = $history;
                }
                continue;
            }
            try {
                $pending[$project] = $this->http->request('GET', sprintf(self::ENDPOINT, $project));
            } catch (\Throwable $e) {
                $this->errors[] = "{$project}: {$e->getMessage()}";
            }
        }

        foreach ($pending as $project => $response) {
            try {
                if ($response->getStatusCode() !== 200) {
                    continue;
                }
                $xml = $response->getContent();
            } catch (\Throwable $e) {
                $this->errors[] = "{$project}: {$e->getMessage()}";
                $stale = $this->readCache($project, true);
                if ($stale !== null && ($history = ReleaseHistory::fromXml($project, $stale)) !== null) {
                    $results[$project] = $history;
                }
                continue;
            }
            $history = ReleaseHistory::fromXml($project, $xml);
            $this->writeCache($project, $xml);
            if ($history !== null) {
                $results[$project] = $history;
            }
        }

        return $results;
    }

    /**
     * Network errors from the last fetch, for warnings.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    private function cacheFile(string $project): string
    {
        return $this->cacheDir . '/' . $project . '.xml';
    }

    private function readCache(string $project, bool $allowStale = false): ?string
    {
        $file = $this->cacheFile($project);
        if (!is_file($file)) {
            return null;
        }
        if (!$allowStale && time() - (int) filemtime($file) > $this->ttl) {
            return null;
        }
        $contents = file_get_contents($file);
        return $contents === false ? null : $contents;
    }

    private function writeCache(string $project, string $xml): void
    {
        try {
            Path::writeAtomic($this->cacheFile($project), $xml);
        } catch (\RuntimeException) {
            // Cache is an optimisation only.
        }
    }
}
