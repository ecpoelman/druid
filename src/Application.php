<?php

declare(strict_types=1);

namespace Druid;

use Druid\Command\DoctorCommand;
use Druid\Command\InitCommand;
use Druid\Command\PlanCommand;
use Druid\Command\ReportCommand;
use Druid\Command\RollbackCommand;
use Druid\Command\ScanCommand;
use Druid\Command\Site\SiteAddCommand;
use Druid\Command\Site\SiteDiscoverCommand;
use Druid\Command\Site\SiteListCommand;
use Druid\Command\Site\SiteRemoveCommand;
use Druid\Command\StatusCommand;
use Druid\Command\UpgradeCheckCommand;
use Druid\Command\UpgradeCommand;
use Druid\Command\UpgradeCoreCommand;
use Druid\Command\UpgradeMajorCommand;
use Druid\Command\UpgradeModuleCommand;
use Druid\Output\Symbols;
use Druid\Output\Theme;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The druid console application: global options, `@alias` handling and command registry.
 */
final class Application extends BaseApplication
{
    public const VERSION = '1.0.0';

    private readonly Services $services;

    public function __construct(?Services $services = null)
    {
        parent::__construct('druid', self::VERSION);
        $this->services = $services ?? new Services();

        $this->addCommands([
            new StatusCommand(),
            new ScanCommand(),
            new ReportCommand(),
            new PlanCommand(),
            new UpgradeCommand(),
            new UpgradeCoreCommand(),
            new UpgradeModuleCommand(),
            new UpgradeMajorCommand(),
            new UpgradeCheckCommand(),
            new RollbackCommand(),
            new DoctorCommand(),
            new InitCommand(),
            new SiteAddCommand(),
            new SiteRemoveCommand(),
            new SiteListCommand(),
            new SiteDiscoverCommand(),
        ]);
    }

    public function services(): Services
    {
        return $this->services;
    }

    /**
     * Builds console input from argv, turning a Drush-style `@sitename` token into `--site=sitename`.
     *
     * @param list<string> $argv
     */
    public static function inputFromArgv(array $argv): ArgvInput
    {
        return new ArgvInput(self::rewriteAliasArguments($argv));
    }

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    public static function rewriteAliasArguments(array $argv): array
    {
        foreach ($argv as $index => $arg) {
            if ($index === 0) {
                continue;
            }
            if ($arg === '--') {
                break;
            }
            if (preg_match('/^@([A-Za-z0-9_.-]+)$/', $arg, $matches) === 1) {
                $argv[$index] = '--site=' . $matches[1];
                break;
            }
        }
        return array_values($argv);
    }

    public function getLongVersion(): string
    {
        return sprintf('<info>druid</info> version <comment>%s</comment> - Drupal upgrade shell', $this->getVersion());
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOption(new InputOption('root', 'r', InputOption::VALUE_REQUIRED, 'Drupal project root (Composer root or docroot). Defaults to the current directory.'));
        $definition->addOption(new InputOption('site', null, InputOption::VALUE_REQUIRED, 'Registered site name. Same as passing @name.'));
        $definition->addOption(new InputOption('uri', 'l', InputOption::VALUE_REQUIRED, 'Site URI passed to Drush (multisite).'));
        $definition->addOption(new InputOption('yes', 'y', InputOption::VALUE_NONE, 'Answer yes to all confirmation prompts.'));
        $definition->addOption(new InputOption('simulate', null, InputOption::VALUE_NONE, 'Show what would happen without changing anything.'));
        return $definition;
    }

    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        parent::configureIO($input, $output);
        Theme::apply($output);
    }

    /**
     * Runtime problems (unknown site, missing project) print as one red line; console usage errors
     * and verbose runs keep Symfony's full rendering.
     */
    public function renderThrowable(\Throwable $e, OutputInterface $output): void
    {
        if ($e instanceof ConsoleExceptionInterface || $output->isVerbose()) {
            parent::renderThrowable($e, $output);
            return;
        }
        Theme::apply($output);
        $output->writeln('<fail>' . Symbols::FAIL . '</fail> ' . OutputFormatter::escape($e->getMessage()), OutputInterface::VERBOSITY_QUIET);
        $output->writeln('<muted>  Run with -v for details.</muted>', OutputInterface::VERBOSITY_QUIET);
    }
}
