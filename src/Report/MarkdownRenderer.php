<?php

declare(strict_types=1);

namespace Druid\Report;

use Druid\Scan\ScanResult;

/**
 * Markdown report, suitable for tickets and pull requests.
 */
final class MarkdownRenderer implements ReportRenderer
{
    public function render(array $results): string
    {
        $lines = ['# Drupal update report', '', '_Generated ' . date('Y-m-d H:i') . ' by druid_', ''];
        $lines[] = '| Site | Core | Security | Unsupported | Minor | Major |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach ($results as $scan) {
            $s = $scan->summary();
            $core = $scan->core->current . ($scan->core->securityTarget !== null ? " -> **{$scan->core->securityTarget}**" : '');
            $security = $s['security'] === 0 ? '0' : "**{$s['security']}** ({$s['severity']->value})";
            $lines[] = "| {$scan->site} | {$core} | {$security} | {$s['unsupported']} | {$s['minor']} | {$s['major']} |";
        }

        foreach ($results as $scan) {
            $rows = ReportRows::build([$scan]);
            $lines[] = '';
            $lines[] = "## {$scan->site}";
            $lines[] = '';
            if ($rows === []) {
                $lines[] = 'Everything is up to date.';
                continue;
            }
            $lines[] = '| Package | Category | Severity | Installed | Recommended | Advisories | Note |';
            $lines[] = '|---|---|---|---|---|---|---|';
            foreach ($rows as $row) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s | %s |',
                    $row['package'],
                    $row['category'],
                    $row['severity'],
                    $row['installed'],
                    $row['recommended'],
                    $row['advisories'],
                    str_replace('|', '/', $row['note']),
                );
            }
            foreach ($scan->warnings as $warning) {
                $lines[] = '';
                $lines[] = "> {$warning}";
            }
        }
        return implode("\n", $lines) . "\n";
    }

    public function extension(): string
    {
        return 'md';
    }
}
