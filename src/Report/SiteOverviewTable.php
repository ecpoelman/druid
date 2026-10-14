<?php

declare(strict_types=1);

namespace Druid\Report;

use Druid\Output\DruidStyle;
use Druid\Output\Symbols;
use Druid\Scan\ScanResult;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One row per site: core, security, unsupported, minor and major counts.
 */
final class SiteOverviewTable
{
    /**
     * @param list<ScanResult> $results
     */
    public function render(array $results, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(['Site', 'Core', 'Core status', 'Security', 'Unsupported', 'Minor', 'Major', 'Scanned']);
        usort($results, static fn (ScanResult $a, ScanResult $b): int => $b->summary()['severity']->rank() <=> $a->summary()['severity']->rank() ?: strnatcasecmp($a->site, $b->site));

        foreach ($results as $scan) {
            $summary = $scan->summary();
            $core = $scan->core;
            $coreStatus = match (true) {
                $core->securityTarget !== null => DruidStyle::severity($core->severity(), Symbols::WARN . ' ' . Symbols::ARROW . ' ' . $core->securityTarget),
                !$core->supported => '<high>unsupported</high>',
                $core->latestPatch !== null => '<low>' . Symbols::ARROW . ' ' . $core->latestPatch . '</low>',
                default => '<ok>' . Symbols::OK . ' current</ok>',
            };
            $table->addRow([
                '@' . $scan->site,
                $core->current,
                $coreStatus,
                $summary['security'] === 0 ? '<ok>0</ok>' : DruidStyle::severity($summary['severity'], (string) $summary['security']),
                DruidStyle::count($summary['unsupported'], 'high'),
                DruidStyle::count($summary['minor'], 'low'),
                DruidStyle::count($summary['major'], 'muted'),
                '<muted>' . substr($scan->scannedAt, 0, 16) . '</muted>',
            ]);
        }
        $table->render();
    }
}
