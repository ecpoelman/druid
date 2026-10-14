<?php

declare(strict_types=1);

namespace Druid\Site\Discovery;

use Druid\Environment\ProcessRunner;
use Druid\Site\DrupalProjectDetector;
use Druid\Site\Site;
use Druid\Util\Path;

/**
 * Laravel Herd: `herd links`, falling back to Herd's symlink folder, plus parked paths from Herd's config.
 */
final class HerdDiscovery implements DiscoverySource
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    public function name(): string
    {
        return 'herd';
    }

    public function discover(): iterable
    {
        $valet = Path::home() . '/Library/Application Support/Herd/config/valet';

        if ($this->runner->has('herd')) {
            yield from $this->links();
        } elseif (is_dir($valet . '/Sites')) {
            yield from $this->symlinks($valet . '/Sites');
        }

        yield from $this->parkedPaths($valet . '/config.json');
    }

    /**
     * Parses the table printed by `herd links`.
     *
     * @return iterable<Site>
     */
    private function links(): iterable
    {
        $result = $this->runner->run(['herd', 'links'], null, [], null, 30);
        foreach (self::parseLinksTable($result->output) as [$name, $url, $path]) {
            $root = DrupalProjectDetector::findComposerRoot($path);
            if ($root !== null) {
                yield new Site($name, $root, $url ?: "https://{$name}.test", 'herd');
            }
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> name, url, path
     */
    public static function parseLinksTable(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|') || str_contains($line, '| Site ')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 6 || $parts[1] === '' || !str_starts_with($parts[4], '/')) {
                continue;
            }
            $rows[] = [$parts[1], $parts[3], $parts[4]];
        }
        return $rows;
    }

    /**
     * @return iterable<Site>
     */
    private function symlinks(string $dir): iterable
    {
        foreach (scandir($dir) ?: [] as $entry) {
            $link = $dir . '/' . $entry;
            if ($entry[0] === '.' || !is_link($link)) {
                continue;
            }
            $target = readlink($link);
            if ($target === false) {
                continue;
            }
            if (!str_starts_with($target, '/')) {
                $target = $dir . '/' . $target;
            }
            $root = DrupalProjectDetector::findComposerRoot($target);
            if ($root !== null) {
                yield new Site($entry, $root, "https://{$entry}.test", 'herd');
            }
        }
    }

    /**
     * @return iterable<Site>
     */
    private function parkedPaths(string $configFile): iterable
    {
        $config = DrupalProjectDetector::readJson($configFile);
        foreach ((array) ($config['paths'] ?? []) as $path) {
            if (!is_string($path)) {
                continue;
            }
            yield from (new SitesDirectoryDiscovery(Path::expand(rtrim($path, '/')), 'herd'))->discover();
        }
    }
}
