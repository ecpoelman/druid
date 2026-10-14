<?php

declare(strict_types=1);

namespace Druid\Site\Discovery;

use Druid\Site\Site;

/**
 * A place where local Drupal sites can be found.
 */
interface DiscoverySource
{
    public function name(): string;

    /**
     * @return iterable<Site>
     */
    public function discover(): iterable;
}
