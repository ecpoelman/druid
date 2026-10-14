<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Output\DruidStyle;
use Druid\Site\DrupalProjectDetector;
use Druid\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Overview of a site: versions, tooling, environment, Git and last scan.
 */
#[AsCommand(name: 'status', description: 'Show site versions, tooling, environment and last scan summary', aliases: ['st'])]
final class StatusCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $context = $this->context($input);
        $site = $context->site;
        $runner = $context->runner;

        $php = $context->environment->php(['-r', 'echo PHP_VERSION;']);
        $phpResult = $runner->run($php->command, $context->root(), $php->env, null, 120);
        $branch = $runner->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $context->root(), [], null, 30);
        $dirty = $branch->successful()
            ? trim($runner->run(['git', 'status', '--porcelain', '--untracked-files=no'], $context->root(), [], null, 60)->output) !== ''
            : null;
        $drushStatus = $context->drush?->status();
        $registered = $this->services()->registry()->findByRoot($site->root) !== null;
        $scan = $this->services()->state()->lastScan($site);

        $data = [
            'site' => $site->name,
            'registered' => $registered,
            'root' => $site->root,
            'docroot' => DrupalProjectDetector::docroot($site->root),
            'url' => $site->url,
            'environment' => $context->environment->label(),
            'drupal' => $context->project->installedCoreVersion(),
            'php' => $phpResult->successful() ? trim($phpResult->output) : null,
            'composer' => $context->composer->version(),
            'drush' => $context->drush?->info->version,
            'bootstrap' => $drushStatus['bootstrap'] ?? null,
            'database' => $drushStatus['db-name'] ?? null,
            'git_branch' => $branch->successful() ? trim($branch->output) : null,
            'git_clean' => $dirty === null ? null : !$dirty,
            'last_scan' => $scan?->scannedAt,
            'summary' => $scan?->summaryArray(),
        ];

        if ($this->stringOption($input, 'format') === 'json') {
            $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $io->heading('@' . $site->name . ($registered ? '' : ' (not tracked - run `druid site:add`)'));
        $io->keyValue('Drupal', $data['drupal'] !== null ? '<title>' . $data['drupal'] . '</title>' : '<fail>unknown</fail>');
        $io->keyValue('Composer root', Path::shorten($site->root));
        $io->keyValue('Docroot', Path::shorten((string) $data['docroot']));
        if ($site->url !== null) {
            $io->keyValue('URL', $site->url);
        }
        $io->keyValue('Environment', $data['environment']);
        $io->keyValue('PHP', $data['php'] ?? '<fail>not found</fail>');
        $io->keyValue('Composer', $data['composer'] ?? '<fail>not found</fail>');
        $io->keyValue('Drush', $data['drush'] !== null ? $data['drush'] : '<warn>not installed</warn>');
        if ($context->drush !== null) {
            $io->keyValue('Bootstrap', $data['bootstrap'] === 'Successful' ? '<ok>Successful</ok>' : '<warn>' . ($data['bootstrap'] ?? 'failed') . '</warn>');
        }
        if ($data['git_branch'] !== null) {
            $io->keyValue('Git', $data['git_branch'] . ($data['git_clean'] ? ' <ok>(clean)</ok>' : ' <warn>(uncommitted changes)</warn>'));
        }

        if ($scan === null) {
            $io->keyValue('Last scan', '<muted>never - run `druid scan`</muted>');
        } else {
            $summary = $scan->summary();
            $io->keyValue('Last scan', $scan->scannedAt);
            $io->keyValue('Security', $summary['security'] === 0 ? '<ok>up to date</ok>' : DruidStyle::severity($summary['severity'], $summary['security'] . ' update(s)'));
            $io->keyValue('Minor updates', DruidStyle::count($summary['minor'], 'low'));
            $io->keyValue('Major updates', DruidStyle::count($summary['major'], 'muted'));
            $io->keyValue('Unsupported', DruidStyle::count($summary['unsupported'], 'high'));
        }
        return self::SUCCESS;
    }
}
