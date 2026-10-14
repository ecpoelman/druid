<?php

declare(strict_types=1);

namespace Druid\Drupal;

use Druid\Security\VersionResolver;

/**
 * Parsed drupal.org release history for one project - the same data core's Update module uses.
 */
final class ReleaseHistory
{
    /**
     * @param list<string> $supportedBranches Composer-form prefixes, e.g. ["1.", "2.0."]
     * @param list<Release> $releases newest first
     */
    public function __construct(
        public readonly string $project,
        public readonly string $title,
        public readonly string $status,
        public readonly array $supportedBranches,
        public readonly array $releases,
    ) {
    }

    public static function fromXml(string $project, string $xml): ?self
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);
        if ($doc === false || $doc->getName() !== 'project') {
            return null;
        }

        $branches = array_values(array_filter(array_map(
            [DrupalVersion::class, 'branchToComposer'],
            explode(',', (string) $doc->supported_branches),
        )));

        $releases = [];
        foreach ($doc->releases->release ?? [] as $release) {
            $drupalVersion = (string) $release->version;
            if ($drupalVersion === '' || preg_match('/^[4-7]\.x-/', $drupalVersion) === 1) {
                continue;
            }
            $types = [];
            foreach ($release->terms->term ?? [] as $term) {
                if ((string) $term->name === 'Release type') {
                    $types[] = (string) $term->value;
                }
            }
            $security = $release->security;
            $coreCompatibility = trim((string) $release->core_compatibility);
            $releases[] = new Release(
                DrupalVersion::toComposer($drupalVersion),
                $drupalVersion,
                (string) $release->status,
                ((string) $release->date) !== '' ? (int) $release->date : null,
                $security !== null && (string) ($security['covered'] ?? '') === '1',
                $types,
                $coreCompatibility === '' ? null : $coreCompatibility,
            );
        }

        return new self(
            $project,
            (string) $doc->title,
            (string) $doc->project_status ?: 'published',
            $branches,
            $releases,
        );
    }

    /** Project marked unsupported or insecure on drupal.org. */
    public function isUnsupported(): bool
    {
        return in_array($this->status, ['unsupported', 'insecure', 'revoked'], true);
    }

    public function isBranchSupported(string $version): bool
    {
        if ($this->supportedBranches === []) {
            return true;
        }
        $normalized = ltrim($version, 'vV');
        foreach ($this->supportedBranches as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function release(string $version): ?Release
    {
        foreach ($this->releases as $release) {
            if (VersionResolver::compare($release->version, $version) === 0) {
                return $release;
            }
        }
        return null;
    }

    /**
     * Published release versions (Composer form).
     *
     * @return list<string>
     */
    public function versions(): array
    {
        $versions = [];
        foreach ($this->releases as $release) {
            if ($release->isPublished() && VersionResolver::isRelease($release->version)) {
                $versions[] = $release->version;
            }
        }
        return $versions;
    }

    /**
     * Published releases that declare support for every given core version, newest first.
     *
     * @param list<string> $coreVersions
     * @param array<string, ?string> $composerCore version => drupal/core constraint from Composer metadata (preferred)
     * @return list<Release>
     */
    public function releasesSupportingCore(array $coreVersions, bool $stableOnly = true, array $composerCore = []): array
    {
        $matches = array_values(array_filter($this->releases, static function (Release $release) use ($coreVersions, $stableOnly, $composerCore): bool {
            if (!$release->isPublished() || !VersionResolver::isRelease($release->version) || ($stableOnly && !$release->isStable())) {
                return false;
            }
            foreach ($coreVersions as $core) {
                if (!$release->supportsCore($core, $composerCore)) {
                    return false;
                }
            }
            return true;
        }));
        usort($matches, static fn (Release $a, Release $b): int => VersionResolver::compare($b->version, $a->version));
        return $matches;
    }
}
