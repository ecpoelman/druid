<?php

declare(strict_types=1);

namespace Druid\Site;

use Druid\Util\Path;
use Symfony\Component\Yaml\Yaml;

/**
 * Tracked sites, stored in ~/.druid/sites.yml.
 */
final class SiteRegistry
{
    /** @var array<string, Site>|null */
    private ?array $sites = null;

    public function __construct(private readonly string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * @return array<string, Site> keyed by name, sorted alphabetically
     */
    public function all(): array
    {
        if ($this->sites === null) {
            $this->sites = [];
            if (is_file($this->file)) {
                $data = Yaml::parseFile($this->file);
                foreach ((array) ($data['sites'] ?? []) as $name => $entry) {
                    if (is_array($entry) && isset($entry['root'])) {
                        $this->sites[(string) $name] = Site::fromArray((string) $name, $entry);
                    }
                }
            }
            ksort($this->sites, SORT_NATURAL | SORT_FLAG_CASE);
        }
        return $this->sites;
    }

    public function get(string $name): ?Site
    {
        return $this->all()[$name] ?? null;
    }

    public function findByRoot(string $root): ?Site
    {
        $normalized = Path::normalize($root);
        foreach ($this->all() as $site) {
            if (Path::normalize($site->root) === $normalized) {
                return $site;
            }
        }
        return null;
    }

    /**
     * Adds or replaces a site. A different name for an already-registered root replaces the old entry.
     */
    public function add(Site $site): void
    {
        $this->all();
        $existing = $this->findByRoot($site->root);
        if ($existing !== null && $existing->name !== $site->name) {
            unset($this->sites[$existing->name]);
        }
        $this->sites[$site->name] = $site;
        ksort($this->sites, SORT_NATURAL | SORT_FLAG_CASE);
        $this->save();
    }

    public function remove(string $name): bool
    {
        $this->all();
        if (!isset($this->sites[$name])) {
            return false;
        }
        unset($this->sites[$name]);
        $this->save();
        return true;
    }

    /**
     * A name not yet used in the registry, based on `$preferred`.
     */
    public function uniqueName(string $preferred): string
    {
        $name = Path::slug($preferred);
        $candidate = $name;
        $i = 2;
        while ($this->get($candidate) !== null) {
            $candidate = "{$name}-{$i}";
            $i++;
        }
        return $candidate;
    }

    private function save(): void
    {
        $data = ['sites' => []];
        foreach ($this->all() as $name => $site) {
            $data['sites'][$name] = $site->toArray();
        }
        Path::writeAtomic($this->file, "# druid site registry - managed by `druid site:*` commands.\n" . Yaml::dump($data, 4, 2));
    }
}
