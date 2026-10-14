<?php

declare(strict_types=1);

namespace Druid\Command\Site;

use Druid\Command\DruidCommand;
use Druid\Output\Symbols;
use Druid\Site\DrupalProjectDetector;
use Druid\Site\Site;
use Druid\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers a Drupal project so it can be tracked and targeted with @name.
 */
#[AsCommand(name: 'site:add', description: 'Track a local Drupal site (defaults to the current project)', aliases: ['sa'])]
final class SiteAddCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to the Drupal project (Composer root or docroot)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Site name used with @name (defaults to the folder name)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Local URL, e.g. https://mysite.test')
            ->addOption('site-uri', null, InputOption::VALUE_REQUIRED, 'Drush --uri for multisite installs')
            ->setHelp(<<<'HELP'
Examples:
  <info>druid site:add</info>                         Track the project in the current directory
  <info>druid site:add ~/Sites/mcv --name=mcv</info>  Track a project by path
HELP);
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

        $registry = $this->services()->registry();
        $existing = $registry->findByRoot($root);
        $name = $this->stringOption($input, 'name') ?? $existing?->name ?? $registry->uniqueName(basename($root));

        $site = new Site(
            $name,
            $root,
            $this->stringOption($input, 'url') ?? $existing?->url ?? 'https://' . basename($root) . '.test',
            $existing?->source ?? 'manual',
            $this->stringOption($input, 'site-uri') ?? $existing?->uri,
        );
        $registry->add($site);

        $io->okLine(sprintf('%s @%s %s %s', $existing ? 'Updated' : 'Added', $name, Symbols::ARROW, Path::shorten($root)));
        $io->mutedLine("Run `druid @{$name} scan` to check it for updates.", 2);
        return self::SUCCESS;
    }
}
