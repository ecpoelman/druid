<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Site\Site;
use Druid\Util\Path;

/**
 * Snapshots under ~/.druid/snapshots/<site-slug>/<timestamp>-<label>/.
 */
final class SnapshotStore
{
    public function __construct(private readonly string $dir)
    {
    }

    public function create(Site $site, string $label, ?string $coreVersion = null): Snapshot
    {
        $base = date('Ymd-His') . '-' . Path::slug($label);
        $id = $base;
        for ($n = 2; is_dir($this->siteDir($site) . '/' . $id); $n++) {
            $id = "{$base}-{$n}";
        }
        $path = $this->siteDir($site) . '/' . $id;
        Path::ensureDirectory($path);

        $files = [];
        foreach (FileSnapshot::FILES as $file) {
            $source = $site->root . '/' . $file;
            if (is_file($source)) {
                copy($source, $path . '/' . $file);
                $files[] = $file;
            }
        }

        $meta = [
            'site' => $site->name,
            'root' => $site->root,
            'label' => $label,
            'created_at' => date(DATE_ATOM),
            'core_version' => $coreVersion,
            'files' => $files,
        ];
        file_put_contents($path . '/meta.json', (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return new Snapshot($id, $path, $meta);
    }

    /**
     * @return list<Snapshot> newest first
     */
    public function all(Site $site): array
    {
        $dir = $this->siteDir($site);
        if (!is_dir($dir)) {
            return [];
        }
        $snapshots = [];
        foreach (scandir($dir, SCANDIR_SORT_DESCENDING) ?: [] as $entry) {
            $meta = $dir . '/' . $entry . '/meta.json';
            if ($entry[0] === '.' || !is_file($meta)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($meta), true);
            $snapshots[] = new Snapshot($entry, $dir . '/' . $entry, is_array($data) ? $data : []);
        }
        return $snapshots;
    }

    public function find(Site $site, string $id): ?Snapshot
    {
        foreach ($this->all($site) as $snapshot) {
            if ($snapshot->id === $id || str_starts_with($snapshot->id, $id)) {
                return $snapshot;
            }
        }
        return null;
    }

    public function latest(Site $site): ?Snapshot
    {
        return $this->all($site)[0] ?? null;
    }

    private function siteDir(Site $site): string
    {
        return $this->dir . '/' . $site->slug();
    }
}
