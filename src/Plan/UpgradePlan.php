<?php

declare(strict_types=1);

namespace Druid\Plan;

/**
 * Ordered steps for one site: core security first, then contrib security, minor updates, major work.
 */
final class UpgradePlan
{
    /**
     * @param list<Step> $steps
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $site,
        public readonly string $scope,
        public readonly array $steps,
        public readonly array $notes = [],
        public readonly ?string $targetCore = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    public function hasStep(string $id): bool
    {
        return $this->step($id) !== null;
    }

    public function step(string $id): ?Step
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site' => $this->site,
            'scope' => $this->scope,
            'target_core' => $this->targetCore,
            'steps' => array_map(static fn (Step $s): array => $s->toArray(), $this->steps),
            'notes' => $this->notes,
        ];
    }
}
