<?php

declare(strict_types=1);

namespace Druid\Upgrade;

/**
 * Outcome of Verifier::verify().
 */
final class VerificationResult
{
    /**
     * @param list<string> $messages
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $messages,
    ) {
    }

    /**
     * @param list<string> $warnings
     */
    public static function passed(array $warnings = []): self
    {
        return new self(true, $warnings);
    }

    /**
     * @param list<string> $messages
     */
    public static function failed(array $messages): self
    {
        return new self(false, $messages);
    }
}
