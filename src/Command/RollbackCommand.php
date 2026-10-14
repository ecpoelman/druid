<?php

declare(strict_types=1);

namespace Druid\Command;

use Druid\Upgrade\FailureAnalyzer;
use Druid\Upgrade\Snapshot;
use Druid\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restores composer.json/composer.lock (and optionally the database) from a pre-upgrade snapshot.
 */
#[AsCommand(name: 'rollback', description: 'Restore Composer files (and optionally the database) from a snapshot', aliases: ['rb'])]
final class RollbackCommand extends DruidCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Snapshot id (default: the latest)')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List snapshots instead of restoring')
            ->addOption('with-db', null, InputOption::VALUE_NONE, 'Also restore the database dump taken before the upgrade')
            ->setHelp(<<<'HELP'
Examples:
  <info>druid rollback --list</info>             Snapshots for the current site
  <info>druid rollback</info>                    Restore the latest snapshot (code only)
  <info>druid rollback --with-db</info>          Restore code and database
  <info>druid @mcv rollback --to=20261014-101500-security</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $context = $this->context($input);
        $store = $this->services()->snapshots();

        if ($this->flag($input, 'list')) {
            $snapshots = $store->all($context->site);
            if ($snapshots === []) {
                $io->infoLine("No snapshots for @{$context->site->name} yet - one is taken before every upgrade.");
                return self::SUCCESS;
            }
            $table = new Table($output);
            $table->setHeaders(['Id', 'Created', 'Scope', 'Core before', 'Database', 'Result']);
            foreach ($snapshots as $snapshot) {
                $table->addRow([
                    $snapshot->id,
                    $snapshot->createdAt(),
                    $snapshot->label(),
                    $snapshot->coreVersion() ?? '?',
                    $snapshot->databaseDump() !== null ? '<ok>yes</ok>' : '<muted>no</muted>',
                    self::resultSummary($snapshot),
                ]);
            }
            $table->render();
            return self::SUCCESS;
        }

        $id = $this->stringOption($input, 'to');
        $snapshot = $id !== null ? $store->find($context->site, $id) : $store->latest($context->site);
        if ($snapshot === null) {
            $io->failLine($id !== null ? "Snapshot {$id} not found (see `druid rollback --list`)." : "No snapshots for @{$context->site->name}.");
            return self::FAILURE;
        }

        $withDb = $this->flag($input, 'with-db');
        $io->heading("Rollback @{$context->site->name} to {$snapshot->id}");
        $io->keyValue('Taken', $snapshot->createdAt());
        $io->keyValue('Core before', $snapshot->coreVersion() ?? '?');
        $io->keyValue('Snapshot', Path::shorten($snapshot->path));
        if ($withDb && $snapshot->databaseDump() === null) {
            $io->failLine('This snapshot has no database dump.');
            return self::FAILURE;
        }
        if ($withDb && $context->drush === null) {
            $io->failLine('Restoring the database needs Drush in the project.');
            return self::FAILURE;
        }

        if ($this->simulate($input)) {
            $io->infoLine('Simulation (--simulate): nothing was changed.');
            return self::SUCCESS;
        }
        $question = $withDb ? 'Restore Composer files AND the database? Current data will be replaced.' : 'Restore composer.json/composer.lock and reinstall vendor?';
        if (!$this->confirm($io, $input, $question, !$withDb)) {
            $io->warnLine('Cancelled.');
            return self::SUCCESS;
        }

        $snapshot->restoreFiles($context->root());
        $io->okLine('Restored composer.json and composer.lock');

        $io->mutedLine('composer install…', 2);
        $install = $context->composer->install();
        if (!$install->successful()) {
            $io->failLine('composer install failed: ' . FailureAnalyzer::summary($install->combined()));
            return self::FAILURE;
        }
        $io->okLine('Vendor matches the restored lock file');

        $exit = self::SUCCESS;
        if ($withDb) {
            $dump = (string) $snapshot->databaseDump();
            $file = $dump;
            if (str_ends_with($dump, '.gz')) {
                $file = substr($dump, 0, -3);
                if (!self::gunzip($dump, $file)) {
                    $io->failLine('Could not decompress the database dump.');
                    return self::FAILURE;
                }
            }
            $restore = $context->drush->sqlRestore($file);
            if ($file !== $dump) {
                @unlink($file);
            }
            if ($restore->successful()) {
                $io->okLine('Database restored');
            } else {
                $io->failLine('Database restore failed: ' . FailureAnalyzer::summary($restore->combined()));
                $exit = self::FAILURE;
            }
        }

        if ($context->drush !== null) {
            $rebuild = $context->drush->cacheRebuild();
            $rebuild->successful() ? $io->okLine('drush cache:rebuild') : $io->warnLine('drush cache:rebuild failed: ' . FailureAnalyzer::summary($rebuild->combined()));
        }
        if (!$withDb && $snapshot->databaseDump() !== null) {
            $io->infoLine('Database updates that already ran are not undone - add --with-db to restore the database too.');
        }

        $this->services()->state()->appendHistory($context->site, [
            'at' => date(DATE_ATOM),
            'command' => 'rollback',
            'snapshot' => $snapshot->id,
            'with_db' => $withDb,
            'exit' => $exit,
        ]);
        return $exit;
    }

    private static function resultSummary(Snapshot $snapshot): string
    {
        $result = $snapshot->meta()['result'] ?? null;
        if (!is_array($result)) {
            return '<muted>-</muted>';
        }
        $steps = is_array($result['steps'] ?? null) ? $result['steps'] : [];
        $upgraded = count(array_filter($steps, static fn ($s): bool => is_array($s) && ($s['status'] ?? '') === 'upgraded'));
        $deferred = count(array_filter($steps, static fn ($s): bool => is_array($s) && ($s['status'] ?? '') === 'deferred'));
        return "{$upgraded} upgraded" . ($deferred > 0 ? ", <warn>{$deferred} deferred</warn>" : '');
    }

    private static function gunzip(string $source, string $target): bool
    {
        $in = @gzopen($source, 'rb');
        $out = @fopen($target, 'wb');
        if ($in === false || $out === false) {
            return false;
        }
        while (!gzeof($in)) {
            $chunk = gzread($in, 1 << 20);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);
        return true;
    }
}
