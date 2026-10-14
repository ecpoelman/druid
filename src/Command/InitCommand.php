<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Site\DrupalProjectDetector;
use Druid\Site\Site;
use Druid\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes a commented druid.yml into the project and registers the site.
 */
#[AsCommand(name: 'init', description: 'Create a druid.yml for this project and start tracking it')]
final class InitCommand extends DruidCommand
{
    public const TEMPLATE = <<<'YAML'
# druid configuration for this project. Every key is optional; defaults are shown.
# Global settings live in ~/.druid/druid.yml; this file wins over it.

# auto | native | ddev | lando
environment: auto

upgrade:
  # Database dump (drush sql:dump) before an upgrade run.
  backup: true
  # Strategies tried in order when a step fails. Remove one to skip it.
  strategies:
    - isolated
    - require_bump
    - blockers
    - all_dependencies
    - no_audit_blocking
    - core_first
    - step_down
    - lenient
  # composer validate + drush cache:rebuild after every step.
  verify: true
  # Drush commands after the run.
  post_update:
    - updatedb
    - cache:rebuild
  export_config: false

packages:
  # Never touch these packages.
  exclude: []
  #   - drupal/some_patched_module
  # Keep packages inside a constraint.
  pin: {}
  #   drupal/core: "~10.6.0"

YAML;

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Project path (default: current directory)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Site name for @name')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing druid.yml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $path = $input->getArgument('path');
        $start = is_string($path) ? Path::expand($path) : ($this->stringOption($input, 'root') ?? (getcwd() ?: '.'));
        $root = DrupalProjectDetector::findComposerRoot($start);
        if ($root === null) {
            $io->failLine("No Drupal Composer project found at {$start}.");
            return self::FAILURE;
        }

        $file = $root . '/druid.yml';
        if (is_file($file) && !$this->flag($input, 'force')) {
            $io->infoLine(Path::shorten($file) . ' already exists (use --force to overwrite).');
        } elseif ($this->simulate($input)) {
            $io->infoLine('Would write ' . Path::shorten($file));
        } else {
            Path::writeAtomic($file, self::TEMPLATE);
            $io->okLine('Wrote ' . Path::shorten($file));
        }

        $registry = $this->services()->registry();
        $existing = $registry->findByRoot($root);
        if ($existing !== null) {
            $io->okLine("Already tracked as @{$existing->name}");
        } elseif (!$this->simulate($input)) {
            $name = $this->stringOption($input, 'name') ?? $registry->uniqueName(basename($root));
            $registry->add(new Site($name, $root, 'https://' . basename($root) . '.test', 'manual'));
            $io->okLine("Tracking as @{$name}");
        }

        $io->newLine();
        $io->mutedLine('Next: druid scan, then druid plan, then druid upgrade.', 0);
        return self::SUCCESS;
    }
}
