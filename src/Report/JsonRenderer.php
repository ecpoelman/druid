<?php

declare(strict_types=1);

namespace Druid\Report;

use Druid\Scan\ScanResult;

/**
 * Full scan data as JSON, with a cross-site summary.
 */
final class JsonRenderer implements ReportRenderer
{
    public function render(array $results): string
    {
        $sites = array_map(static fn (ScanResult $r): array => $r->toArray(), $results);
        $totals = ['sites' => count($results), 'sites_with_security_updates' => 0, 'security' => 0, 'unsupported' => 0, 'minor' => 0, 'major' => 0];
        foreach ($results as $result) {
            $summary = $result->summary();
            $totals['security'] += $summary['security'];
            $totals['unsupported'] += $summary['unsupported'];
            $totals['minor'] += $summary['minor'];
            $totals['major'] += $summary['major'];
            if ($summary['security'] > 0) {
                $totals['sites_with_security_updates']++;
            }
        }
        return (string) json_encode([
            'generated_at' => date(DATE_ATOM),
            'totals' => $totals,
            'sites' => $sites,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public function extension(): string
    {
        return 'json';
    }
}
