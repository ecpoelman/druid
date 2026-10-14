<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Output\DruidStyle;
use Druid\Output\Symbols;
use Druid\Site\DrupalProjectDetector;
use Druid\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Checks that the tools druid relies on are available and working.
 */
#[AsCommand(name: 'doctor', description: 'Check PHP, Composer, Drush, Git, network access and druid storage')]
final class DoctorCommand extends DruidCommand
{
    private int $problems = 0;

    protected function configure(): void
    {
        $this->addOption('offline', null, InputOption::VALUE_NONE, 'Skip network checks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $services = $this->services();
        $runner = $services->processRunner();
        $this->problems = 0;

        $io->heading('Host tools');
        $phpOk = PHP_VERSION_ID >= 80200;
        $this->report($io, $phpOk, 'PHP ' . PHP_VERSION . ' running druid', $phpOk ? null : 'druid needs PHP 8.2+');

        foreach (['composer' => true, 'git' => true, 'drush' => false, 'ddev' => false, 'lando' => false, 'herd' => false] as $tool => $required) {
            $path = $runner->resolveExecutable($tool);
            if ($path !== null) {
                $io->okLine(sprintf('%s found at %s', $tool, Path::shorten($path)));
            } elseif ($required) {
                $this->report($io, false, "{$tool} not found", "Install {$tool} and make sure it is on PATH.");
            } else {
                $io->mutedLine(Symbols::SKIP . " {$tool} not installed (optional)", 2);
            }
        }

        $composer = $runner->run(['composer', '--version', '--no-ansi'], null, ['COMPOSER_NO_INTERACTION' => '1'], null, 60);
        if ($composer->successful()) {
            $line = strtok($composer->output, "\n") ?: '';
            $io->okLine(trim($line));
        }

        $io->heading('druid storage');
        $home = $services->home();
        try {
            Path::ensureDirectory($home);
            $this->report($io, is_writable($home), 'Home ' . Path::shorten($home) . ' is writable');
        } catch (\RuntimeException $e) {
            $this->report($io, false, $e->getMessage());
        }
        $sites = $services->registry()->all();
        $io->okLine(sprintf('%d tracked site(s) in %s', count($sites), Path::shorten($services->registry()->file())));
        $services->globalConfig();
        foreach ($services->configLoader()->loadedFiles() as $file) {
            $io->infoLine('Config: ' . Path::shorten($file));
        }

        if (!$this->flag($input, 'offline')) {
            $io->heading('Network');
            $client = HttpClient::create(['timeout' => 10]);
            foreach ([
                'updates.drupal.org' => 'https://updates.drupal.org/release-history/drupal/current',
                'packages.drupal.org' => 'https://packages.drupal.org/8/packages.json',
                'repo.packagist.org' => 'https://repo.packagist.org/packages.json',
            ] as $host => $url) {
                try {
                    $status = $client->request('GET', $url)->getStatusCode();
                    $this->report($io, $status < 400, "{$host} reachable (HTTP {$status})");
                } catch (\Throwable $e) {
                    $this->report($io, false, "{$host} unreachable", $e->getMessage());
                }
            }
        }

        $this->siteChecks($io, $input);

        $io->newLine();
        if ($this->problems === 0) {
            $io->writeln('<ok>' . Symbols::OK . ' Everything looks good.</ok>');
            return self::SUCCESS;
        }
        $io->writeln(sprintf('<fail>%s %d problem(s) found.</fail>', Symbols::FAIL, $this->problems));
        return self::FAILURE;
    }

    private function siteChecks(DruidStyle $io, InputInterface $input): void
    {
        $start = $this->stringOption($input, 'root') ?? (getcwd() ?: '.');
        if ($this->stringOption($input, 'site') === null && DrupalProjectDetector::findComposerRoot($start) === null) {
            return;
        }

        $context = $this->context($input);
        $io->heading('Site @' . $context->site->name);
        $io->okLine('Composer root ' . Path::shorten($context->root()));
        $io->infoLine('Environment: ' . $context->environment->label());
        $io->infoLine('Core: ' . ($context->project->installedCoreVersion() ?? 'unknown'));

        $this->report($io, is_file($context->root() . '/composer.lock'), 'composer.lock present', 'Run `composer install` to create composer.lock.');
        $this->report($io, is_dir(DrupalProjectDetector::vendorDir($context->root())), 'vendor/ installed', 'Run `composer install`.');

        if ($context->drush === null) {
            $io->warnLine('Drush not installed in this project; database updates and verification are skipped. Add it with `composer require drush/drush`.');
            return;
        }
        $io->okLine('Drush ' . ($context->drush->info->version ?? '?') . ' at ' . Path::shorten($context->drush->info->binary));
        $status = $context->drush->status();
        $boots = ($status['bootstrap'] ?? '') === 'Successful';
        $this->report($io, $boots, 'Drupal bootstraps through Drush', 'Start the database (Herd/DDEV/MySQL) so druid can run updatedb and verify upgrades.');
    }

    private function report(DruidStyle $io, bool $ok, string $message, ?string $hint = null): void
    {
        if ($ok) {
            $io->okLine($message);
            return;
        }
        $this->problems++;
        $io->failLine($message);
        if ($hint !== null) {
            $io->mutedLine($hint);
        }
    }
}
