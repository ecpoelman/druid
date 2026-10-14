<?php

declare(strict_types=1);

namespace Druid\State;

use Druid\Scan\ScanResult;
use Druid\Site\Site;
use Druid\Util\Path;

/**
 * Per-site state in ~/.druid/state/<slug>.json: last scan and upgrade history.
 */
final class StateStore
{
    private const HISTORY_LIMIT = 200;

    public function __construct(private readonly string $dir)
    {
    }

    public function saveScan(Site $site, ScanResult $scan): void
    {
        $state = $this->read($site);
        $state['site'] = $site->name;
        $state['root'] = $site->root;
        $state['last_scan'] = $scan->toArray();
        $this->write($site, $state);
    }

    public function lastScan(Site $site): ?ScanResult
    {
        $data = $this->read($site)['last_scan'] ?? null;
        return is_array($data) ? ScanResult::fromArray($data) : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function appendHistory(Site $site, array $entry): void
    {
        $state = $this->read($site);
        $history = (array) ($state['history'] ?? []);
        $history[] = ['at' => date(DATE_ATOM)] + $entry;
        $state['history'] = array_slice($history, -self::HISTORY_LIMIT);
        $this->write($site, $state);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(Site $site): array
    {
        return array_values((array) ($this->read($site)['history'] ?? []));
    }

    private function file(Site $site): string
    {
        return $this->dir . '/' . $site->slug() . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Site $site): array
    {
        $file = $this->file($site);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(Site $site, array $state): void
    {
        Path::writeAtomic($this->file($site), (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
