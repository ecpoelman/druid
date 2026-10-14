<?php

declare(strict_types=1);

namespace Druid\Report;

/**
 * One CSV row per package needing attention, across all sites.
 */
final class CsvRenderer implements ReportRenderer
{
    public function render(array $results): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for CSV output.');
        }
        fputcsv($handle, ReportRows::HEADERS, ',', '"', '\\');
        foreach (ReportRows::build($results) as $row) {
            fputcsv($handle, array_values($row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    public function extension(): string
    {
        return 'csv';
    }
}
