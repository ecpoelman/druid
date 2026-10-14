<?php

declare(strict_types=1);

namespace Druid\Site;

/**
 * Resolves the site a command acts on: `@alias`/--site, then --root, then the current directory.
 */
final class SiteLocator
{
    public function __construct(private readonly SiteRegistry $registry)
    {
    }

    public function locate(?string $siteName, ?string $root, string $cwd): Site
    {
        if ($siteName !== null) {
            $site = $this->registry->get($siteName);
            if ($site === null) {
                $known = implode(', ', array_keys($this->registry->all())) ?: 'none';
                throw new \RuntimeException("Unknown site @{$siteName}. Registered sites: {$known}. Add it with `druid site:add`.");
            }
            if (!is_dir($site->root)) {
                throw new \RuntimeException("Site @{$siteName} points to {$site->root}, which no longer exists. Update it with `druid site:add`.");
            }
            return $site;
        }

        $start = $root ?? $cwd;
        $composerRoot = DrupalProjectDetector::findComposerRoot($start);
        if ($composerRoot === null) {
            throw new \RuntimeException(sprintf(
                'No Drupal Composer project found at %s. Run druid inside a Drupal project, pass --root=PATH, or use @sitename.',
                $start,
            ));
        }

        return $this->registry->findByRoot($composerRoot)
            ?? new Site(basename($composerRoot), $composerRoot, null, 'cwd');
    }
}
