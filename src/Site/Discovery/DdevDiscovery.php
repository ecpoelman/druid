<?php

declare(strict_types=1);

namespace Druid\Site\Discovery;

use Druid\Environment\ProcessRunner;
use Druid\Site\DrupalProjectDetector;
use Druid\Site\Site;

/**
 * DDEV projects from `ddev list --json-output`.
 */
final class DdevDiscovery implements DiscoverySource
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    public function name(): string
    {
        return 'ddev';
    }

    public function discover(): iterable
    {
        if (!$this->runner->has('ddev')) {
            return;
        }
        $result = $this->runner->run(['ddev', 'list', '--json-output'], null, [], null, 60);
        foreach (self::parseList($result->output) as $project) {
            $root = DrupalProjectDetector::findComposerRoot($project['approot']);
            if ($root !== null) {
                yield new Site($project['name'], $root, $project['url'], 'ddev');
            }
        }
    }

    /**
     * @return list<array{name: string, approot: string, url: ?string}>
     */
    public static function parseList(string $output): array
    {
        $projects = [];
        foreach (explode("\n", trim($output)) as $line) {
            $data = json_decode(trim($line), true);
            if (!is_array($data) || !isset($data['raw']) || !is_array($data['raw'])) {
                continue;
            }
            foreach ($data['raw'] as $item) {
                if (!is_array($item) || !isset($item['name'], $item['approot'])) {
                    continue;
                }
                $projects[] = [
                    'name' => (string) $item['name'],
                    'approot' => (string) $item['approot'],
                    'url' => isset($item['primary_url']) ? (string) $item['primary_url'] : (isset($item['httpsurl']) ? (string) $item['httpsurl'] : null),
                ];
            }
        }
        return $projects;
    }
}
