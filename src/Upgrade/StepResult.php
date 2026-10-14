<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Plan\Step;

/**
 * Outcome of one plan step.
 */
final class StepResult
{
    public const UPGRADED = 'upgraded';
    public const SATISFIED = 'satisfied';
    public const DONE = 'done';
    public const DEFERRED = 'deferred';
    public const SKIPPED = 'skipped';

    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly Step $step,
        public readonly string $status,
        public readonly ?string $achieved = null,
        public readonly ?string $strategy = null,
        public readonly int $attempts = 0,
        public readonly ?Diagnosis $diagnosis = null,
        public readonly string $message = '',
        public readonly array $warnings = [],
    ) {
    }

    public function succeeded(): bool
    {
        return in_array($this->status, [self::UPGRADED, self::SATISFIED, self::DONE], true);
    }

    public function changedCode(): bool
    {
        return $this->status === self::UPGRADED || ($this->status === self::DONE && $this->step->kind === Step::LENIENT);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step->id,
            'title' => $this->step->title,
            'status' => $this->status,
            'from' => $this->step->from,
            'target' => $this->step->to,
            'achieved' => $this->achieved,
            'strategy' => $this->strategy,
            'attempts' => $this->attempts,
            'message' => $this->message,
            'warnings' => $this->warnings,
            'diagnosis' => $this->diagnosis?->toArray(),
        ];
    }
}
