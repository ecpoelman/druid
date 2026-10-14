<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Plan\PlanOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows the upgrade steps and exact commands without changing anything.
 */
#[AsCommand(name: 'plan', description: 'Outline the upgrade steps and commands without changing anything', aliases: ['pl'])]
final class PlanCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this->addScopeOptions();
        $this
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to these packages (repeatable; drupal/ prefix optional)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table or json', 'table')
            ->setHelp(<<<'HELP'
Examples:
  <info>druid plan</info>                  Security updates for the current project
  <info>druid plan --minor</info>          Security + minor/patch updates
  <info>druid plan --major</info>          Full path to the next Drupal major
  <info>druid @mcv plan -p webform</info>  Only webform
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $json = $this->stringOption($input, 'format') === 'json';
        $context = $this->context($input);
        $scan = $this->scanFor($context, $json ? $this->errorStyle($input, $output) : $io, $this->flag($input, 'rescan'));

        $packages = array_values(array_map('strval', (array) $input->getOption('package')));
        $plan = $this->planner($context)->build($scan, new PlanOptions(
            $this->scopeFromInput($input),
            $packages,
            true,
            true,
            $this->flag($input, 'latest'),
            $this->stringOption($input, 'to'),
        ));

        if ($json) {
            $output->writeln((string) json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->planPrinter($context)->print($plan, $io);
        if (!$plan->isEmpty()) {
            $io->newLine();
            $io->mutedLine(sprintf('Apply with: druid%s upgrade%s', $context->site->source === 'cwd' ? '' : ' @' . $context->site->name, $this->scopeFlag($input)), 0);
        }
        return self::SUCCESS;
    }

    private function scopeFlag(InputInterface $input): string
    {
        $flags = '';
        foreach (['minor', 'major', 'latest'] as $flag) {
            if ($this->flag($input, $flag)) {
                $flags .= " --{$flag}";
            }
        }
        if (($to = $this->stringOption($input, 'to')) !== null) {
            $flags .= " --to={$to}";
        }
        foreach ((array) $input->getOption('package') as $package) {
            $flags .= " -p {$package}";
        }
        return $flags;
    }
}
