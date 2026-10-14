<?php

declare(strict_types=1);

namespace Druid\Composer;

/**
 * One "Problem N" block from Composer's dependency resolver output, classified.
 */
final class ComposerProblem
{
    public function __construct(
        public readonly int $number,
        /** exact_core_pin | locked_core | locked_package | audit_blocked | locked_cascade | php_requirement | other */
        public readonly string $kind,
        public readonly ?string $package,
        public readonly string $summary,
        public readonly string $detail,
        public readonly ?string $suggestedCommand = null,
        public readonly bool $resolvesWithCore = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'kind' => $this->kind,
            'package' => $this->package,
            'summary' => $this->summary,
            'detail' => $this->detail,
            'suggested_command' => $this->suggestedCommand,
            'resolves_with_core' => $this->resolvesWithCore,
        ];
    }
}
