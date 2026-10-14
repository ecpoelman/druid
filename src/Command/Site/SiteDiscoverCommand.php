<?php

declare(strict_types=1);

namespace Druid\Command\Site;

use Druid\Command\DruidCommand;
use Druid\Output\Symbols;
use Druid\Site\Discovery\SiteDiscoverer;
use Druid\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Finds local Drupal sites (Herd, DDEV, ~/Sites, Drush aliases) and registers them.
 */
#[AsCommand(name: 'site:discover', description: 'Find local Drupal sites (Herd, DDEV, ~/Sites, Drush aliases) and track them', aliases: ['discover'])]
final class SiteDiscoverCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Extra directory to scan (repeatable)')
            ->setHelp('Discovery sources are configured under `discovery:` in ~/.druid/druid.yml. Use --simulate to preview.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $services = $this->services();
        $config = $services->globalConfig();

        $extraPaths = (array) $input->getOption('path');
        if ($extraPaths !== []) {
            $config = $config->with(['discovery' => ['paths' => array_merge($config->list('discovery.paths'), $extraPaths)]]);
        }

        $discoverer = new SiteDiscoverer($config, $services->processRunner());
        $io->writeln('<label>Discovering Drupal sites…</label>');
        foreach ($discoverer->sources() as $source) {
            $io->mutedLine($source->name(), 2);
        }

        $found = $discoverer->discover();
        $registry = $services->registry();
        $added = 0;

        foreach ($found as $site) {
            $existing = $registry->findByRoot($site->root);
            if ($existing !== null) {
                $io->mutedLine(sprintf('%s @%s already tracked (%s)', Symbols::BULLET, $existing->name, Path::shorten($site->root)), 2);
                continue;
            }
            $site = $site->withName($registry->uniqueName($site->name));
            if (!$this->simulate($input)) {
                $registry->add($site);
            }
            $added++;
            $io->okLine(sprintf('@%s  %s  <%s>', $site->name, Path::shorten($site->root), $site->source));
        }

        $io->newLine();
        $verb = $this->simulate($input) ? 'Would add' : 'Added';
        $io->writeln(sprintf('<ok>%s %d new site(s)</ok>, %d found in total. Registry: %s', $verb, $added, count($found), Path::shorten($registry->file())));
        return self::SUCCESS;
    }
}
