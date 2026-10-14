<?php

declare(strict_types=1);

namespace Druid\Command\Site;

use Druid\Command\DruidCommand;
use Druid\Output\DruidStyle;
use Druid\Site\DrupalProjectDetector;
use Druid\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists tracked sites with their last known update status.
 */
#[AsCommand(name: 'site:list', description: 'List tracked sites and their last known update status', aliases: ['sites', 'sl'])]
final class SiteListCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table, json or names', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $sites = $this->services()->registry()->all();
        $state = $this->services()->state();
        $format = $this->stringOption($input, 'format') ?? 'table';

        if ($format === 'names') {
            foreach (array_keys($sites) as $name) {
                $output->writeln($name);
            }
            return self::SUCCESS;
        }

        if ($format === 'json') {
            $data = [];
            foreach ($sites as $name => $site) {
                $scan = $state->lastScan($site);
                $data[$name] = $site->toArray() + ['summary' => $scan?->summaryArray(), 'scanned_at' => $scan?->scannedAt];
            }
            $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($sites === []) {
            $io->warnLine('No sites tracked yet.');
            $io->mutedLine('Run `druid site:discover` to find local Drupal sites, or `druid site:add` inside a project.', 2);
            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Site', 'Core', 'Security', 'Minor', 'Major', 'Last scan', 'Path']);
        foreach ($sites as $name => $site) {
            $scan = $state->lastScan($site);
            $exists = DrupalProjectDetector::isDrupalComposerRoot($site->root);
            if ($scan === null) {
                $table->addRow([
                    '@' . $name,
                    '<muted>?</muted>',
                    '<muted>-</muted>',
                    '<muted>-</muted>',
                    '<muted>-</muted>',
                    $exists ? '<muted>never</muted>' : '<fail>missing</fail>',
                    Path::shorten($site->root),
                ]);
                continue;
            }
            $summary = $scan->summary();
            $core = $scan->core->current . ($scan->core->securityTarget !== null ? ' <critical> SA </critical>' : '');
            $table->addRow([
                '@' . $name,
                $core,
                $summary['security'] === 0 ? '<ok>0</ok>' : DruidStyle::severity($summary['severity'], (string) $summary['security']),
                DruidStyle::count($summary['minor'], 'low'),
                DruidStyle::count($summary['major'], 'muted'),
                self::age($scan->scannedAt),
                Path::shorten($site->root),
            ]);
        }
        $table->render();
        $io->mutedLine('Refresh with `druid scan --all`; details with `druid report --all`.', 0);
        return self::SUCCESS;
    }

    private static function age(string $iso): string
    {
        $time = strtotime($iso);
        if ($time === false) {
            return '?';
        }
        $diff = time() - $time;
        $text = match (true) {
            $diff < 3600 => max(1, intdiv($diff, 60)) . 'm ago',
            $diff < 86400 => intdiv($diff, 3600) . 'h ago',
            default => intdiv($diff, 86400) . 'd ago',
        };
        return $diff > 7 * 86400 ? "<warn>{$text}</warn>" : $text;
    }
}
