<?php

declare(strict_types=1);

namespace Druid\Drupal;

use Druid\Security\VersionResolver;

/**
 * One release from the drupal.org release-history feed, with its version in Composer form.
 */
final class Release
{
    /**
     * @param list<string> $releaseTypes e.g. ["Security update", "Bug fixes"]
     */
    public function __construct(
        public readonly string $version,
        public readonly string $drupalVersion,
        public readonly string $status,
        public readonly ?int $date,
        public readonly bool $securityCovered,
        public readonly array $releaseTypes,
        public readonly ?string $coreCompatibility,
    ) {
    }

    public function isSecurityUpdate(): bool
    {
        return in_array('Security update', $this->releaseTypes, true);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isStable(): bool
    {
        return VersionResolver::isStable($this->version);
    }

    /**
     * True when this release declares support for the given Drupal core version.
     * Releases without core_compatibility (core itself) are treated as compatible.
     *
     * @param array<string, ?string> $composerCore version => drupal/core constraint from Composer metadata,
     *        which wins over the feed's core_compatibility because it is what Composer resolves against
     */
    public function supportsCore(string $coreVersion, array $composerCore = []): bool
    {
        $constraint = array_key_exists($this->version, $composerCore) ? $composerCore[$this->version] : $this->coreCompatibility;
        return $constraint === null || VersionResolver::satisfies($coreVersion, $constraint);
    }
}
