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
 * Upgrades individual contrib modules, themes or libraries.
 */
#[AsCommand(name: 'upgrade:module', description: 'Upgrade one or more modules/themes/libraries', aliases: ['up:m', 'upgrade:modules', 'um'])]
final class UpgradeModuleCommand extends AbstractUpgradeCommand
{
    protected function configure(): void
    {
        $this->addRunOptions();
        $this
            ->addArgument('names', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Module names (drupal/ prefix optional) or vendor/package')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Exact target version (single package only)')
            ->addOption('major', null, InputOption::VALUE_NONE, 'Allow moving to a new major version of the module')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Newest release instead of the minimum safe one for security fixes')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Scan again instead of reusing a recent scan')
            ->setHelp(<<<'HELP'
Examples:
  <info>druid upgrade:module webform</info>             Security fix or latest minor of webform
  <info>druid up:m token pathauto</info>                Several modules
  <info>druid up:m paragraphs --major</info>            Allow a new major of paragraphs
  <info>druid up:m webform --to=6.2.9</info>            Exact version
  <info>druid up:m guzzlehttp/guzzle</info>             A library
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $names = array_values(array_map('strval', (array) $input->getArgument('names')));
        $to = $this->stringOption($input, 'to');
        if ($to !== null && count($names) > 1) {
            $io->failLine('--to only works with a single package.');
            return self::INVALID;
        }

        return $this->upgradeSite($input, $io, $this->resolveSite($input), fn (ScanResult $scan): PlanOptions => new PlanOptions(
            $this->flag($input, 'major') ? Step::SCOPE_MAJOR : Step::SCOPE_MINOR,
            $names,
            false,
            true,
            $this->flag($input, 'latest'),
            $to,
        ));
    }
}
