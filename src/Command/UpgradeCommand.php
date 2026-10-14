<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Plan\PlanOptions;
use Druid\Scan\ScanResult;
use Druid\Site\Site;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Upgrades a whole site: core first, then contrib, in the chosen scope.
 */
#[AsCommand(name: 'upgrade', description: 'Upgrade a complete site (core + contrib) - security by default, --minor or --major for more', aliases: ['up'])]
final class UpgradeCommand extends AbstractUpgradeCommand
{
    protected function configure(): void
    {
        $this->addScopeOptions();
        $this->addRunOptions();
        $this
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Upgrade every registered site')
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to these packages (repeatable)')
            ->addOption('no-core', null, InputOption::VALUE_NONE, 'Leave Drupal core alone')
            ->addOption('rector', null, InputOption::VALUE_NONE, 'Apply drupal-rector to custom modules (fixes deprecated code; review with git diff)')
            ->setHelp(<<<'HELP'
Runs the plan from <info>druid plan</info>. Every step is snapshotted; if a step fails druid tries
other strategies, and a step that still fails is rolled back and deferred while the rest continues.

Examples:
  <info>druid upgrade</info>                 Security updates for the current project
  <info>druid up --minor -y</info>           Security + minor updates without prompting
  <info>druid @mcv upgrade --major</info>    Move @mcv to the next Drupal major
  <info>druid upgrade --all</info>           Security updates on every registered site
  <info>druid upgrade --simulate</info>      Show the plan only

Exit codes: 0 done, 2 finished with deferred steps, 1 failed.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $packages = array_values(array_map('strval', (array) $input->getOption('package')));
        $scope = $this->scopeFromInput($input);

        return $this->forEachSite($input, $io, fn (Site $site): int => $this->upgradeSite(
            $input,
            $io,
            $site,
            fn (ScanResult $scan): PlanOptions => new PlanOptions(
                $scope,
                $packages,
                !$this->flag($input, 'no-core'),
                true,
                $this->flag($input, 'latest'),
                $this->stringOption($input, 'to'),
                $this->flag($input, 'rector'),
            ),
        ));
    }
}
