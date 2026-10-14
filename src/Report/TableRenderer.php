<?php

declare(strict_types=1);

namespace Druid\Report;

use Druid\Output\DruidStyle;
use Druid\Output\Symbols;
use Druid\Scan\PackageStatus;
use Druid\Scan\ScanResult;
use Druid\Security\Advisory;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;

/**
 * Colour-coded console view of one site's scan.
 */
final class TableRenderer
{
    public function __construct(private readonly bool $securityOnly = false)
    {
    }

    public function renderSite(ScanResult $scan, DruidStyle $io): void
    {
        $this->renderCore($scan, $io);
        $this->renderSecurity($scan, $io);
        $this->renderUnsupported($scan, $io);
        if (!$this->securityOnly) {
            $this->renderMinor($scan, $io);
            $this->renderMajor($scan, $io);
            $this->renderCustom($scan, $io);
        }
        foreach ($scan->warnings as $warning) {
            $io->warnLine($warning);
        }
        $this->renderTotals($scan, $io);
    }

    private function renderCore(ScanResult $scan, DruidStyle $io): void
    {
        $core = $scan->core;
        $io->heading('Drupal core ' . $core->current);

        if ($core->securityTarget !== null) {
            $io->writeln(sprintf(
                '  %s %s security update %s <title>%s</title>',
                DruidStyle::severity($core->severity(), ' ' . strtoupper($core->severity()->label()) . ' '),
                Symbols::WARN,
                Symbols::ARROW,
                $core->securityTarget,
            ));
            foreach ($core->advisories as $advisory) {
                $io->mutedLine(self::advisoryLine($advisory));
            }
        } elseif ($core->advisories !== []) {
            $io->warnLine('Open advisories but no fixed release could be determined. Check https://www.drupal.org/security');
        } else {
            $io->okLine('No open security advisories for core');
        }

        if ($core->latestPatch !== null && $core->latestPatch !== $core->securityTarget) {
            $io->infoLine("Latest patch release: {$core->latestPatch}");
        }
        if ($core->latestMinor !== null && $core->latestMinor !== $core->latestPatch) {
            $io->infoLine("Latest minor release: {$core->latestMinor}  (druid upgrade --minor)");
        }
        if ($core->nextMajor !== null) {
            $io->infoLine("Next major available: {$core->nextMajor}  (druid upgrade:check)");
        }
        foreach ($core->notes as $note) {
            $io->warnLine($note);
        }
    }

    private function renderSecurity(ScanResult $scan, DruidStyle $io): void
    {
        $items = $scan->byCategory(PackageStatus::SECURITY);
        $io->heading(sprintf('Security updates (%d)', count($items)));
        if ($items === []) {
            $io->okLine('No contrib or library security advisories');
            return;
        }
        usort($items, static fn (PackageStatus $a, PackageStatus $b): int => $b->severity()->rank() <=> $a->severity()->rank() ?: strcmp($a->package, $b->package));

        $table = $this->table($io, ['Package', 'Installed', 'Recommended', 'Latest', 'Severity', 'Advisories']);
        foreach ($items as $item) {
            $ids = array_map(static fn (Advisory $a): string => $a->cve ?? $a->id, $item->advisories);
            $table->addRow([
                $item->package . ($item->direct ? '' : ' <muted>(dependency)</muted>'),
                $item->current,
                $item->securityTarget !== null ? '<ok>' . $item->securityTarget . '</ok>' : '<fail>no fix yet</fail>',
                $item->latestMinor ?? $item->latestMajor ?? '-',
                DruidStyle::severity($item->severity()),
                DruidStyle::escape(implode(', ', array_slice($ids, 0, 3)) . (count($ids) > 3 ? ' +' . (count($ids) - 3) : '')),
            ]);
        }
        $table->render();
    }

    private function renderUnsupported(ScanResult $scan, DruidStyle $io): void
    {
        $items = array_values(array_filter($scan->packages, static fn (PackageStatus $p): bool => $p->isUnsupported()));
        if ($items === []) {
            return;
        }
        $io->heading(sprintf('Unsupported or abandoned (%d)', count($items)));
        $table = $this->table($io, ['Package', 'Installed', 'Status', 'Recommendation']);
        foreach ($items as $item) {
            $status = match (true) {
                $item->abandoned => 'abandoned',
                $item->projectStatus !== null && $item->projectStatus !== 'published' => $item->projectStatus,
                default => 'branch unsupported',
            };
            $advice = match (true) {
                $item->replacement !== null => "Replace with {$item->replacement}",
                $item->abandoned || $status !== 'branch unsupported' => 'Find a replacement or remove it',
                $item->latestMinor !== null => "Move to a supported release, e.g. {$item->latestMinor}",
                $item->latestMajor !== null => "Move to a supported major: {$item->latestMajor}",
                default => 'Check the project page for a supported branch',
            };
            $table->addRow(['<high>' . $item->package . '</high>', $item->current, "<warn>{$status}</warn>", DruidStyle::escape($advice)]);
        }
        $table->render();
    }

    private function renderMinor(ScanResult $scan, DruidStyle $io): void
    {
        $items = $scan->byCategory(PackageStatus::MINOR);
        $io->heading(sprintf('Minor and patch updates (%d)', count($items)));
        if ($items === []) {
            $io->okLine('Everything is on the newest release of its current major');
            return;
        }
        $table = $this->table($io, ['Package', 'Installed', 'Available', 'Enabled']);
        foreach ($items as $item) {
            $table->addRow([
                $item->package . ($item->dev ? ' <muted>(dev)</muted>' : ''),
                $item->current,
                '<low>' . $item->latestMinor . '</low>',
                self::enabled($item),
            ]);
        }
        $table->render();
    }

    private function renderMajor(ScanResult $scan, DruidStyle $io): void
    {
        $items = array_values(array_filter($scan->packages, static fn (PackageStatus $p): bool => $p->hasMajorUpdate() && $p->direct));
        if ($items === []) {
            return;
        }
        $io->heading(sprintf('New major versions available (%d)', count($items)));
        $table = $this->table($io, ['Package', 'Installed', 'Newest major', 'Enabled']);
        foreach ($items as $item) {
            $table->addRow([$item->package, $item->current, '<muted>' . $item->latestMajor . '</muted>', self::enabled($item)]);
        }
        $table->render();
        $io->mutedLine('Major contrib upgrades can need config or code changes. Upgrade them individually: druid upgrade:module NAME --major', 2);
    }

    private function renderCustom(ScanResult $scan, DruidStyle $io): void
    {
        if ($scan->customModules === []) {
            return;
        }
        $io->heading(sprintf('Custom code (%d)', count($scan->customModules)));
        $io->writeln('  ' . DruidStyle::escape(implode(', ', array_keys($scan->customModules))));
        $io->mutedLine('Checked for deprecations by `druid upgrade:check` (upgrade_status) and fixed by `druid upgrade:major --rector`.', 2);
    }

    private function renderTotals(ScanResult $scan, DruidStyle $io): void
    {
        $summary = $scan->summary();
        $io->newLine();
        $parts = [
            'Security ' . ($summary['security'] === 0 ? '<ok>0</ok>' : DruidStyle::severity($summary['severity'], (string) $summary['security'])),
            'Unsupported ' . DruidStyle::count($summary['unsupported'], 'high'),
            'Minor ' . DruidStyle::count($summary['minor'], 'low'),
            'Major ' . DruidStyle::count($summary['major'], 'muted'),
        ];
        $io->writeln('  <title>Summary</title>  ' . implode('  <muted>|</muted>  ', $parts));
        $env = $scan->environment;
        $io->mutedLine(sprintf(
            'PHP %s · Composer %s · Drush %s · %s%s',
            $env['php'] ?? '?',
            $env['composer'] ?? '?',
            $env['drush'] ?? 'none',
            $env['environment'] ?? '?',
            isset($env['git_branch']) ? ' · git ' . $env['git_branch'] : '',
        ), 2);
    }

    /**
     * @param list<string> $headers
     */
    private function table(DruidStyle $io, array $headers): Table
    {
        $table = new Table($io);
        $style = (new TableStyle())
            ->setHorizontalBorderChars('─')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('─', '─', '─', '─', '─', '─', '─', '─', '─')
            ->setCellHeaderFormat('<label>%s</label>');
        $table->setStyle($style)->setHeaders($headers);
        return $table;
    }

    private static function enabled(PackageStatus $item): string
    {
        return match ($item->enabled) {
            true => '<ok>yes</ok>',
            false => '<warn>no</warn>',
            null => '<muted>?</muted>',
        };
    }

    private static function advisoryLine(Advisory $advisory): string
    {
        $id = $advisory->cve !== null ? "{$advisory->id} / {$advisory->cve}" : $advisory->id;
        return Symbols::BULLET . " {$id}: {$advisory->title}" . ($advisory->link !== null ? " ({$advisory->link})" : '');
    }
}
