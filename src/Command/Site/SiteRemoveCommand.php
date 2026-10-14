<?php

declare(strict_types=1);

namespace Druid\Command\Site;

use Druid\Command\DruidCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stops tracking a site. Project files are not touched.
 */
#[AsCommand(name: 'site:remove', description: 'Stop tracking a site (files are not touched)', aliases: ['site:rm'])]
final class SiteRemoveCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Site name(s), with or without @');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $registry = $this->services()->registry();
        $status = self::SUCCESS;

        foreach ((array) $input->getArgument('name') as $name) {
            $name = ltrim((string) $name, '@');
            if ($registry->remove($name)) {
                $io->okLine("Removed @{$name}");
            } else {
                $io->warnLine("@{$name} is not registered");
                $status = self::FAILURE;
            }
        }
        return $status;
    }
}
