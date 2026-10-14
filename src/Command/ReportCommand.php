<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Report\ReportFactory;
use Druid\Report\SiteOverviewTable;
use Druid\Report\TableRenderer;
use Druid\Scan\ScanResult;
use Druid\Scan\Scanner;
use Druid\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports stored scan results for one or all tracked sites in table, JSON, CSV, Markdown or HTML.
 */
#[AsCommand(name: 'report', description: 'Report update status for one or all tracked sites (table, json, csv, md, html)', aliases: ['rp'])]
final class ReportCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Report on every tracked site')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table, json, csv, md or html', 'table')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the report to a file instead of stdout')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Scan again instead of using stored results')
            ->addOption('details', 'd', InputOption::VALUE_NONE, 'With --all and table format, show every site in detail')
            ->setHelp(<<<'HELP'
Uses the results stored by <info>druid scan</info>; sites never scanned are scanned first.

Examples:
  <info>druid report</info>                                    Current project
  <info>druid report --all</info>                              Overview of every tracked site
  <info>druid report --all --format=html -o ~/report.html</info>   Shareable HTML report
  <info>druid report --all --format=csv</info>                 Spreadsheet-friendly output
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $format = strtolower($this->stringOption($input, 'format') ?? 'table');
        $file = $this->stringOption($input, 'output');
        $services = $this->services();
        $results = [];

        foreach ($this->targetSites($input) as $site) {
            $scan = $this->flag($input, 'rescan') ? null : $services->state()->lastScan($site);
            if ($scan === null) {
                $io->getErrorStyle()->writeln("<muted>Scanning @{$site->name}…</muted>");
                try {
                    $scan = (new Scanner($this->context($input, $site), $services->releaseHistory()))->scan();
                    $services->state()->saveScan($site, $scan);
                } catch (\Throwable $e) {
                    $io->getErrorStyle()->writeln("<fail>@{$site->name}: {$e->getMessage()}</fail>");
                    continue;
                }
            }
            $results[] = $scan;
        }

        if ($results === []) {
            $io->failLine('Nothing to report.');
            return self::FAILURE;
        }

        if ($format === 'table' && $file === null) {
            $this->renderTable($results, $input, $output);
            return self::SUCCESS;
        }

        $renderer = ReportFactory::create($format === 'table' ? 'md' : $format);
        $content = $renderer->render($results);
        if ($file === null) {
            $output->write($content, false, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }

        $path = Path::expand($file);
        Path::writeAtomic($path, $content);
        $io->okLine('Report written to ' . Path::shorten($path));
        return self::SUCCESS;
    }

    /**
     * @param list<ScanResult> $results
     */
    private function renderTable(array $results, InputInterface $input, OutputInterface $output): void
    {
        $io = $this->style($input, $output);
        if (count($results) === 1 && !$this->flag($input, 'all')) {
            (new TableRenderer())->renderSite($results[0], $io);
            return;
        }
        (new SiteOverviewTable())->render($results, $output);
        if ($this->flag($input, 'details')) {
            foreach ($results as $scan) {
                $io->newLine();
                $io->writeln('<step>━━ @' . $scan->site . ' ━━</step>');
                (new TableRenderer())->renderSite($scan, $io);
            }
        } else {
            $io->mutedLine('Details per site: `druid @NAME report`, or add --details.', 0);
        }
    }
}
