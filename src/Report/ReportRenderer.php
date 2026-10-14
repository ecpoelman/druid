<?php

declare(strict_types=1);

namespace Druid\Report;

use Druid\Scan\ScanResult;

/**
 * Renders scan results to a string (file or stdout).
 */
interface ReportRenderer
{
    /**
     * @param list<ScanResult> $results
     */
    public function render(array $results): string;

    public function extension(): string;
}
