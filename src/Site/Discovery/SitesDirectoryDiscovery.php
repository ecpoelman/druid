<?php

declare(strict_types=1);

namespace Druid\Site\Discovery;

use Druid\Site\DrupalProjectDetector;
use Druid\Site\Site;

/**
 * Top-level folders of a directory such as ~/Sites, checking the folder and its web/, docroot/ and public/.
 */
final class SitesDirectoryDiscovery implements DiscoverySource
{
    public function __construct(
        private readonly string $directory,
        private readonly string $source = 'sites',
    ) {
    }

    public function name(): string
    {
        return $this->source . ':' . $this->directory;
    }

    public function discover(): iterable
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $entries = scandir($this->directory) ?: [];
        foreach ($entries as $entry) {
            if ($entry[0] === '.') {
                continue;
            }
            $projectDir = $this->directory . '/' . $entry;
            if (!is_dir($projectDir)) {
                continue;
            }
            foreach (['', '/web', '/docroot', '/public'] as $suffix) {
                $candidate = $projectDir . $suffix;
                if (!is_dir($candidate)) {
                    continue;
                }
                $root = DrupalProjectDetector::findComposerRoot($candidate, $projectDir);
                if ($root !== null) {
                    yield new Site($entry, $root, "https://{$entry}.test", $this->source);
                    break;
                }
            }
        }
    }
}
