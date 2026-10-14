<?php

declare(strict_types=1);

namespace Druid\Upgrade;

/**
 * Outcome of a whole upgrade run.
 */
final class RunResult
{
    /** @var list<StepResult> */
    public array $steps = [];

    /** @var array<string, bool> post-update command => success */
    public array $postUpdate = [];

    /** @var list<string> */
    public array $warnings = [];

    public ?Snapshot $snapshot = null;
    public ?string $fatal = null;

    public function upgradedCount(): int
    {
        return count(array_filter($this->steps, static fn (StepResult $r): bool => $r->status === StepResult::UPGRADED));
    }

    /**
     * @return list<StepResult>
     */
    public function deferred(): array
    {
        return array_values(array_filter($this->steps, static fn (StepResult $r): bool => $r->status === StepResult::DEFERRED));
    }

    public function changedCode(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->changedCode()) {
                return true;
            }
        }
        return false;
    }

    public function exitCode(): int
    {
        if ($this->fatal !== null) {
            return 1;
        }
        if ($this->deferred() !== [] || in_array(false, $this->postUpdate, true)) {
            return 2;
        }
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fatal' => $this->fatal,
            'snapshot' => $this->snapshot?->id,
            'steps' => array_map(static fn (StepResult $r): array => $r->toArray(), $this->steps),
            'post_update' => $this->postUpdate,
            'warnings' => $this->warnings,
        ];
    }
}
