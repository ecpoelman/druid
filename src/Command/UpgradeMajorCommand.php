<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Plan\PlanOptions;
use Druid\Plan\Step;
use Druid\Scan\ScanResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Moves a site to the next Drupal major: latest minor first, compatible contrib, lenient fallbacks, core.
 */
#[AsCommand(name: 'upgrade:major', description: 'Upgrade to the next Drupal major version (e.g. 10 to 11)', aliases: ['up:major'])]
final class UpgradeMajorCommand extends AbstractUpgradeCommand
{
    protected function configure(): void
    {
        $this->addRunOptions();
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Target major or version, e.g. 11, 11.2 or 11.2.5 (default: next major)')
            ->addOption('rector', null, InputOption::VALUE_NONE, 'Apply drupal-rector to custom modules')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Scan again instead of reusing a recent scan')
            ->setHelp(<<<'HELP'
Order of work:
  1. upgrade_status readiness check (when installed; see druid upgrade:check)
  2. security and minor updates, including the latest core minor of the current major
  3. contrib releases that support both majors
  4. lenient plugin for modules that have not declared support yet
  5. drupal-rector on custom code (with --rector; a dry run otherwise)
  6. all core packages to the new major, with modules that only support the new major
  7. drush updatedb and cache:rebuild

Run <info>druid upgrade:check</info> first to see the compatibility of every module.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $target = $input->getArgument('target');
        $target = is_string($target) && $target !== '' ? $target : null;

        return $this->upgradeSite($input, $io, $this->resolveSite($input), fn (ScanResult $scan): PlanOptions => new PlanOptions(
            Step::SCOPE_MAJOR,
            [],
            true,
            true,
            false,
            $target,
            $this->flag($input, 'rector'),
        ));
    }
}
