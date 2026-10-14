<?php

declare(strict_types=1);

namespace Druid\Plan;

/**
 * What to include in a plan.
 */
final class PlanOptions
{
    /**
     * @param list<string> $packages limit to these packages (drupal/ prefix optional); empty means all
     */
    public function __construct(
        /** security | minor | major */
        public readonly string $scope = Step::SCOPE_SECURITY,
        public readonly array $packages = [],
        public readonly bool $includeCore = true,
        public readonly bool $includeContrib = true,
        /** Prefer the newest release instead of the minimum safe one. */
        public readonly bool $latest = false,
        /** Explicit target version for a single package or core, e.g. `10.6.11` or `11` for a major. */
        public readonly ?string $target = null,
        public readonly bool $rector = false,
    ) {
    }

    public function includesMinor(): bool
    {
        return $this->scope === Step::SCOPE_MINOR || $this->scope === Step::SCOPE_MAJOR;
    }

    public function includesMajor(): bool
    {
        return $this->scope === Step::SCOPE_MAJOR;
    }

    /**
     * True when `$package` passes the --package filter.
     */
    public function wants(string $package): bool
    {
        if ($this->packages === []) {
            return true;
        }
        foreach ($this->packages as $wanted) {
            $wanted = strtolower(trim($wanted));
            if ($wanted === strtolower($package) || 'drupal/' . $wanted === strtolower($package)) {
                return true;
            }
        }
        return false;
    }
}
