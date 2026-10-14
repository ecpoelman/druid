<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Output\DruidStyle;
use Druid\Report\SiteOverviewTable;
use Druid\Report\TableRenderer;
use Druid\Scan\ScanResult;
use Druid\Scan\Scanner;
use Druid\Site\Site;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scans one site, or every tracked site, for security, minor and major updates.
 */
#[AsCommand(name: 'scan', description: 'Scan for security advisories and available core/contrib updates', aliases: ['sec', 'security:scan'])]
final class ScanCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Scan every tracked site')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Ignore the drupal.org release-history cache')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table or json', 'table')
            ->addOption('security-only', null, InputOption::VALUE_NONE, 'Only list security items')
            ->setHelp(<<<'HELP'
Runs <info>composer audit</info>, <info>composer outdated</info>, <info>drush pm:security</info> and the drupal.org
release-history feed, then stores the result so <info>druid report</info> and <info>druid site:list</info> can show it.

Examples:
  <info>druid scan</info>              Scan the current project
  <info>druid @mcv scan</info>         Scan a tracked site
  <info>druid scan --all</info>        Scan every tracked site
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $json = $this->stringOption($input, 'format') === 'json';
        $sites = $this->targetSites($input);
        $results = [];
        $failures = 0;

        foreach ($sites as $site) {
            try {
                $results[] = $this->scanSite($site, $input, $io, $json);
            } catch (\Throwable $e) {
                $failures++;
                if (!$json) {
                    $io->failLine("@{$site->name}: {$e->getMessage()}");
                }
            }
        }

        if ($json) {
            $payload = array_map(static fn (ScanResult $r): array => $r->toArray(), $results);
            $output->writeln((string) json_encode(count($sites) === 1 ? ($payload[0] ?? []) : $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $failures > 0 ? self::FAILURE : self::SUCCESS;
        }

        if (count($sites) > 1) {
            $io->newLine();
            (new SiteOverviewTable())->render($results, $output);
        } elseif ($results !== []) {
            (new TableRenderer($this->flag($input, 'security-only')))->renderSite($results[0], $io);
            $io->newLine();
            $io->mutedLine('Next: `druid plan` to see the upgrade steps, or `druid upgrade` to apply security updates.', 0);
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function scanSite(Site $site, InputInterface $input, DruidStyle $io, bool $quiet): ScanResult
    {
        $services = $this->services();
        $context = $this->context($input, $site);
        if (!$quiet) {
            $io->writeln(sprintf('<label>Scanning @%s</label> <muted>(%s)</muted>', $site->name, $context->environment->label()));
        }
        $scanner = new Scanner($context, $services->releaseHistory());
        $result = $scanner->scan($this->flag($input, 'refresh'), $quiet ? null : static function (string $message) use ($io): void {
            $io->mutedLine('… ' . $message, 2);
        });
        $services->state()->saveScan($site, $result);
        return $result;
    }
}
