<?php

declare(strict_types=1);

namespace Druid\Security;

/**
 * Advisory severity, mapped from Composer's severity field or Drupal SA risk labels.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Moderate = 'moderate';
    case Low = 'low';
    case Unknown = 'unknown';
    case None = 'none';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Moderate => 3,
            self::Low => 2,
            self::Unknown => 1,
            self::None => 0,
        };
    }

    /** Console style name from Output\Theme, or null for plain text. */
    public function style(): ?string
    {
        return match ($this) {
            self::Critical => 'critical',
            self::High => 'high',
            self::Moderate => 'moderate',
            self::Low => 'low',
            self::Unknown => 'warn',
            self::None => 'ok',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Severity from Composer's `severity` (critical/high/medium/low) or a Drupal SA title such as
     * "Drupal core - Moderately critical - Access bypass".
     */
    public static function fromAdvisory(?string $severityField, string $title): self
    {
        $field = strtolower(trim((string) $severityField));
        $fromField = match ($field) {
            'critical' => self::Critical,
            'high' => self::High,
            'medium', 'moderate' => self::Moderate,
            'low' => self::Low,
            default => null,
        };
        if ($fromField !== null) {
            return $fromField;
        }

        $lower = strtolower($title);
        return match (true) {
            str_contains($lower, 'highly critical') => self::Critical,
            str_contains($lower, 'moderately critical') => self::Moderate,
            str_contains($lower, 'less critical'), str_contains($lower, 'not critical') => self::Low,
            str_contains($lower, 'critical') => self::High,
            default => self::Unknown,
        };
    }

    /**
     * @param iterable<self> $severities
     */
    public static function highest(iterable $severities): self
    {
        $max = self::None;
        foreach ($severities as $severity) {
            if ($severity->rank() > $max->rank()) {
                $max = $severity;
            }
        }
        return $max;
    }
}
