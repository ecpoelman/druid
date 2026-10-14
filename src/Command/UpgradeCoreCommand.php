<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Plan\PlanOptions;
use Druid\Plan\Step;
use Druid\Scan\ScanResult;
use Druid\Security\VersionResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Upgrades Drupal core only. Defaults to the security release, or the latest patch of the current minor.
 */
#[AsCommand(name: 'upgrade:core', description: 'Upgrade Drupal core (all core packages together)', aliases: ['up:core', 'core'])]
final class UpgradeCoreCommand extends AbstractUpgradeCommand
{
    protected function configure(): void
    {
        $this->addRunOptions();
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Target version, e.g. 10.6.11, or a major such as 11')
            ->addOption('minor', null, InputOption::VALUE_NONE, 'Latest release of the current major instead of the latest patch')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Scan again instead of reusing a recent scan')
            ->addOption('rector', null, InputOption::VALUE_NONE, 'Apply drupal-rector to custom modules (major upgrades)')
            ->setHelp(<<<'HELP'
Examples:
  <info>druid upgrade:core</info>            Security release, or the latest patch of the current minor
  <info>druid upgrade:core --minor</info>    Latest release of the current major
  <info>druid upgrade:core 10.6.11</info>    Exact version
  <info>druid upgrade:core 11</info>         Next major (same as druid upgrade:major 11)
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $site = $this->resolveSite($input);
        $requested = $input->getArgument('version');
        $requested = is_string($requested) && $requested !== '' ? $requested : null;

        return $this->upgradeSite($input, $io, $site, function (ScanResult $scan) use ($requested, $input): PlanOptions {
            $current = $scan->core->current;
            $isMajor = $requested !== null
                && (preg_match('/^\d+(\.x)?$/', $requested) === 1 || VersionResolver::major($requested) > VersionResolver::major($current));
            if ($isMajor) {
                return new PlanOptions(Step::SCOPE_MAJOR, [], true, true, false, $requested, $this->flag($input, 'rector'));
            }

            $target = $requested ?? ($this->flag($input, 'minor')
                ? self::max($scan->core->securityTarget, $scan->core->latestMinor)
                : self::max($scan->core->securityTarget, $scan->core->latestPatch));
            if ($target !== null && preg_match('/^\d+\.\d+$/', $target) === 1) {
                $target .= '.0';
            }
            // An explicit target equal to the current version plans nothing instead of falling back to a minor.
            $target ??= $current;
            $scope = $requested === null && !$this->flag($input, 'minor') && $scan->core->securityTarget !== null
                ? Step::SCOPE_SECURITY
                : Step::SCOPE_MINOR;
            return new PlanOptions($scope, ['drupal/core'], true, false, false, $target);
        });
    }

    private static function max(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }
        return VersionResolver::greaterThan($a, $b) ? $a : $b;
    }
}
