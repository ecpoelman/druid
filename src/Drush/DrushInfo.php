<?php

declare(strict_types=1);

namespace Druid\Drush;

/**
 * A Drush installation inside a project.
 */
final class DrushInfo
{
    public function __construct(
        public readonly string $binary,
        public readonly ?string $version,
    ) {
    }

    public function majorVersion(): int
    {
        return $this->version === null ? 0 : (int) explode('.', $this->version)[0];
    }
}
