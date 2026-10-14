<?php

declare(strict_types=1);

namespace Druid\Report;

/**
 * Maps --format values to file renderers.
 */
final class ReportFactory
{
    public const FORMATS = ['table', 'json', 'csv', 'md', 'html'];

    public static function create(string $format): ReportRenderer
    {
        return match (strtolower($format)) {
            'json' => new JsonRenderer(),
            'csv' => new CsvRenderer(),
            'md', 'markdown' => new MarkdownRenderer(),
            'html' => new HtmlRenderer(),
            default => throw new \InvalidArgumentException(sprintf('Unknown format "%s". Use one of: %s', $format, implode(', ', self::FORMATS))),
        };
    }
}
