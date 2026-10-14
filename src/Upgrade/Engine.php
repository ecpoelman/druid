<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Drupal\PhpRequirements;
use Druid\Environment\ProcessResult;
use Druid\Plan\Step;
use Druid\Plan\UpgradePlan;
use Druid\Scan\ScanResult;
use Druid\Security\VersionResolver;
use Druid\Site\DrupalProjectDetector;
use Druid\SiteContext;
use Druid\Upgrade\Strategy\AttemptContext;
use Druid\Upgrade\Strategy\Strategy;

/**
 * Runs an upgrade plan step by step. Each Composer step climbs the strategy ladder until one attempt
 * succeeds and verifies; every failed attempt is rolled back. A step that never succeeds is deferred
 * with a diagnosis and the run carries on with the next step.
 */
final class Engine
{
    /** Automatic fixes (network retry, memory, plugins, scaffold) tried per failing command. */
    private const MAX_REMEDIES = 3;

    /** Strategies left out of the second pass: their candidates were already tried and may be insecure. */
    private const FIRST_PASS_ONLY = ['step_down'];

    private bool $drupalBoots = false;
    private readonly FailureAnalyzer $analyzer;
    private readonly Verifier $verifier;

    /**
     * @param list<Strategy> $strategies
     * @param list<StepHandler> $handlers
     */
    public function __construct(
        private readonly SiteContext $context,
        private readonly CommandBuilder $builder,
        private readonly array $strategies,
        private readonly array $handlers,
        private readonly SnapshotStore $snapshots,
        private readonly EngineListener $listener,
        private readonly ?ScanResult $scan = null,
        bool $verifyDrupal = true,
    ) {
        $this->analyzer = new FailureAnalyzer($context->project);
        $this->verifier = new Verifier($context->composer, $context->drush, $verifyDrupal);
    }

    public function run(UpgradePlan $plan, EngineOptions $options): RunResult
    {
        $result = new RunResult();
        $this->listener->runStarted($plan);

        if (!$this->preflight($plan, $options, $result)) {
            $this->listener->runFinished($result);
            return $result;
        }

        $total = count($plan->steps);
        foreach ($plan->steps as $index => $step) {
            $this->listener->stepStarted($index + 1, $total, $step);
            $coreLater = array_filter(array_slice($plan->steps, $index + 1), static fn (Step $s): bool => $s->isCore()) !== [];
            $stepResult = $this->safeRunStep($step, $options, $coreLater);
            $result->steps[] = $stepResult;
            $this->listener->stepFinished($stepResult);
        }

        $this->retryStepsThatNeededNewerCore($result, $options);
        $this->postUpdate($result, $options);
        $result->snapshot?->recordResult($result->toArray());
        $this->listener->runFinished($result);
        return $result;
    }

    // MARK: - Preflight

    private function preflight(UpgradePlan $plan, EngineOptions $options, RunResult $result): bool
    {
        $context = $this->context;
        $version = $context->composer->version();
        if ($version === null) {
            $result->fatal = 'Composer is not available for this site. Install Composer or check the DDEV/Lando environment.';
            $this->listener->notice('fail', $result->fatal);
            return false;
        }
        $this->listener->notice('ok', "Composer {$version} ({$context->environment->label()})");

        if (!$context->project->hasLock()) {
            $this->listener->notice('warn', 'composer.lock is missing - Composer will resolve everything from composer.json.');
        }

        $git = $context->runner->run(['git', 'status', '--porcelain', '--', 'composer.json', 'composer.lock'], $context->root(), [], null, 30);
        if ($git->successful() && trim($git->output) !== '') {
            $this->listener->notice('warn', 'composer.json/composer.lock have uncommitted changes. They are included in the snapshot.');
        }

        if ($context->drush !== null) {
            $this->drupalBoots = $context->drush->canBootstrap();
            $this->listener->notice(
                $this->drupalBoots ? 'ok' : 'warn',
                $this->drupalBoots
                    ? 'Drupal bootstraps through Drush - steps are verified with cache:rebuild'
                    : 'Drush cannot bootstrap Drupal (database not running?) - verification, backup and database updates are skipped',
            );
        } else {
            $this->listener->notice('warn', 'Drush not found in this project - database updates must be run manually afterwards');
        }

        $snapshot = $this->snapshots->create($context->site, $plan->scope, $context->project->installedCoreVersion());
        $result->snapshot = $snapshot;
        $this->listener->notice('ok', "Snapshot {$snapshot->id} saved (`druid rollback` restores it)");

        if ($options->backup && $context->drush !== null && $this->drupalBoots) {
            $file = $snapshot->path . '/database.sql';
            $dump = $context->drush->sqlDump($file);
            $saved = is_file($file . '.gz') ? $file . '.gz' : (is_file($file) ? $file : null);
            if ($dump->successful() && $saved !== null) {
                $snapshot->setDatabaseDump($saved);
                $this->listener->notice('ok', 'Database backup saved with drush sql:dump');
            } else {
                $this->listener->notice('warn', 'Database backup failed: ' . FailureAnalyzer::summary($dump->combined()));
            }
        }
        return true;
    }

    // MARK: - Steps

    private function safeRunStep(Step $step, EngineOptions $options, bool $coreLater = false): StepResult
    {
        try {
            return $this->runStep($step, $options, $coreLater);
        } catch (\Throwable $e) {
            return new StepResult($step, StepResult::DEFERRED, null, null, 0, new Diagnosis(Diagnosis::UNKNOWN, $e->getMessage()), $e->getMessage());
        }
    }

    /**
     * Steps deferred because they needed a newer core get one more try once a core step has upgraded,
     * e.g. pantheon-systems/drupal-integrations 11.x after the move to Drupal 11.
     */
    private function retryStepsThatNeededNewerCore(RunResult $result, EngineOptions $options): void
    {
        $coreMoved = false;
        foreach ($result->steps as $stepResult) {
            $coreMoved = $coreMoved || ($stepResult->step->isCore() && $stepResult->status === StepResult::UPGRADED);
        }
        if (!$coreMoved) {
            return;
        }

        $retry = array_filter(
            $result->steps,
            static fn (StepResult $r): bool => $r->status === StepResult::DEFERRED && !$r->step->isCore() && $r->diagnosis?->kind === Diagnosis::NEEDS_NEWER_CORE,
        );
        $total = count($retry);
        $number = 0;
        foreach ($retry as $index => $previous) {
            $number++;
            $this->listener->notice('info', "Core has moved - retrying {$previous->step->package}");
            $this->listener->stepStarted($number, $total, $previous->step);
            $stepResult = $this->safeRunStep($previous->step, $options);
            $result->steps[$index] = $stepResult;
            $this->listener->stepFinished($stepResult);
        }
    }

    /**
     * The major core step can't succeed while PHP is pinned below what the new major needs.
     */
    private function phpBlocker(Step $step): ?Diagnosis
    {
        if ($step->kind !== Step::MAJOR_CORE || $step->to === null) {
            return null;
        }
        $major = VersionResolver::major($step->to);
        $php = $this->scan?->environment['php'] ?? null;
        $php = is_string($php) && $php !== '' ? $php : null;
        $problems = PhpRequirements::problems($major, $php, $this->context->project);
        if ($problems === []) {
            return null;
        }
        return new Diagnosis(Diagnosis::PHP_REQUIREMENT, "Drupal {$major} needs PHP " . PhpRequirements::minimumFor($major) . '+ but the project is pinned lower', $problems, PhpRequirements::fixCommands($major, $this->context->project));
    }

    /**
     * @param bool $coreLater a core step comes later in this run, so "needs newer core" failures can wait for it
     */
    private function runStep(Step $step, EngineOptions $options, bool $coreLater = false): StepResult
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($step)) {
                return $handler->handle($step, $this->listener);
            }
        }
        if (!$step->isComposerStep()) {
            return new StepResult($step, StepResult::SKIPPED, null, null, 0, null, 'No handler for this step type.');
        }

        $installed = $this->installedVersion($step);
        if ($installed !== null && $step->to !== null && VersionResolver::compare($installed, $step->to) >= 0) {
            return new StepResult($step, StepResult::SATISFIED, $installed, null, 0, null, "Already at {$installed}");
        }

        $phpBlocker = $this->phpBlocker($step);
        if ($phpBlocker !== null) {
            return new StepResult($step, StepResult::DEFERRED, $installed, null, 0, $phpBlocker, $phpBlocker->summary);
        }

        $attemptContext = new AttemptContext($this->context->project, $this->context->composer, $this->builder, $this->context->config, $this->scan);
        $sticky = CommandBuilder::baseEnv($step, $options->bypassAuditBlockingForSecurity);
        $tried = [];
        $count = 0;

        // A second pass re-runs the ladder when a failure changed the environment (audit bypass, memory),
        // so earlier strategies get another chance with what was learned.
        for ($pass = 1; $pass <= 2; $pass++) {
            $stickyAtStart = $sticky;
            foreach ($this->strategies as $strategy) {
                if ($pass === 2 && in_array($strategy->name(), self::FIRST_PASS_ONLY, true)) {
                    continue;
                }
                foreach ($strategy->attempts($step, $attemptContext) as $attempt) {
                    $attempt = $attempt->withEnv($sticky);
                    if (isset($tried[$attempt->key()])) {
                        continue;
                    }
                    $tried[$attempt->key()] = true;
                    if ($count >= $options->maxAttemptsPerStep) {
                        break 3;
                    }
                    $count++;

                    $outcome = $this->execute($attempt, $step, $count, $options);
                    if ($outcome['ok']) {
                        $achieved = $this->installedVersion($step);
                        if ($step->from !== null && $achieved !== null && !VersionResolver::greaterThan($achieved, $step->from)) {
                            $diagnosis = new Diagnosis(Diagnosis::DEPENDENCY_CONFLICT, "Composer finished but {$step->package} stayed at {$achieved}", ['Composer kept the locked version - another constraint holds it back.']);
                            $this->listener->attemptFailed($diagnosis, $outcome['output']);
                            $attemptContext->recordFailure($outcome['output'], $diagnosis);
                            continue;
                        }
                        return new StepResult($step, StepResult::UPGRADED, $achieved, $attempt->strategy, $count, null, 'Upgraded', $outcome['warnings']);
                    }

                    $diagnosis = $outcome['diagnosis'] ?? new Diagnosis(Diagnosis::UNKNOWN, 'Composer failed');
                    $attemptContext->recordFailure($outcome['output'], $diagnosis);
                    if ($diagnosis->isAuditBlocked()) {
                        $sticky += CommandBuilder::NO_AUDIT_BLOCKING_ENV;
                    }
                    if ($diagnosis->kind === Diagnosis::MEMORY) {
                        $sticky['COMPOSER_MEMORY_LIMIT'] = '-1';
                    }
                    if ($coreLater && $diagnosis->kind === Diagnosis::NEEDS_NEWER_CORE && !$step->isCore()) {
                        $this->listener->notice('info', 'Needs a newer core - deferred until the core step later in this run, then retried.');
                        break 3;
                    }
                    $unfixable = $diagnosis->kind === Diagnosis::SPAWN_FAILED
                        || $diagnosis->kind === Diagnosis::PLUGIN_BLOCKED
                        || ($diagnosis->kind === Diagnosis::PHP_REQUIREMENT && $step->isCore());
                    if ($unfixable) {
                        // Another version or strategy can't solve these; they need a change outside Composer.
                        break 3;
                    }
                }
            }
            if ($sticky === $stickyAtStart) {
                break;
            }
            $this->listener->notice('info', 'Retrying the strategies with what the failures revealed (' . implode(', ', array_keys(array_diff_key($sticky, $stickyAtStart))) . ')');
        }

        $diagnosis = $attemptContext->lastDiagnosis ?? new Diagnosis(Diagnosis::UNKNOWN, 'No strategy could be applied');
        return new StepResult($step, StepResult::DEFERRED, $this->installedVersion($step), null, $count, $diagnosis, $diagnosis->summary);
    }

    /**
     * @return array{ok: bool, output: string, diagnosis: ?Diagnosis, warnings: list<string>}
     */
    private function execute(Attempt $attempt, Step $step, int $number, EngineOptions $options): array
    {
        $commands = array_merge($attempt->prerequisites, [$attempt]);
        $display = implode(' && ', array_map(
            fn (Attempt $a): string => $this->context->composer->commandLine($a->args, $a->env)->display(),
            $commands,
        ));
        $this->listener->attemptStarted($number, $attempt, $display);

        $snapshot = FileSnapshot::capture($this->context->root());
        $output = '';

        foreach ($commands as $command) {
            $result = $this->runComposer($command, $options);
            $output .= $result->combined() . "\n";
            if ($result->successful()) {
                continue;
            }

            $diagnosis = $result->exitCode === 127
                ? new Diagnosis(Diagnosis::SPAWN_FAILED, FailureAnalyzer::summary($result->combined()), ['Composer could not be started. Is it installed and on PATH?'])
                : $this->analyzer->analyze($result->combined(), $step);

            for ($remedies = 0; $remedies < self::MAX_REMEDIES; $remedies++) {
                $remedy = $this->remedy($diagnosis, $command, $options);
                if ($remedy === null) {
                    break;
                }
                $output .= $remedy->combined() . "\n";
                if ($remedy->successful()) {
                    continue 2;
                }
                $diagnosis = $this->analyzer->analyze($remedy->combined(), $step);
            }

            $this->listener->attemptFailed($diagnosis, $output);
            $this->rollback($snapshot, $output);
            return ['ok' => false, 'output' => $output, 'diagnosis' => $diagnosis, 'warnings' => []];
        }

        if (!$options->verify) {
            return ['ok' => true, 'output' => $output, 'diagnosis' => null, 'warnings' => []];
        }

        $verification = $this->verifier->verify($this->drupalBoots, $step->kind === Step::MAJOR_CORE);
        if (!$verification->ok) {
            $diagnosis = new Diagnosis(Diagnosis::VERIFICATION_FAILED, $verification->messages[0] ?? 'Verification failed', $verification->messages);
            $this->listener->attemptFailed($diagnosis, $output);
            $this->rollback($snapshot, $output);
            return ['ok' => false, 'output' => $output, 'diagnosis' => $diagnosis, 'warnings' => []];
        }
        return ['ok' => true, 'output' => $output, 'diagnosis' => null, 'warnings' => $verification->messages];
    }

    private function runComposer(Attempt $command, EngineOptions $options): ProcessResult
    {
        $listener = $this->listener;
        $onOutput = $options->streamOutput
            ? static function (string $buffer, bool $isError) use ($listener): void {
                $listener->output($buffer, $isError);
            }
            : null;
        return $this->context->composer->run($command->args, $command->env, $onOutput);
    }

    /**
     * Automatic fixes for failures that aren't about dependency resolution.
     */
    private function remedy(Diagnosis $diagnosis, Attempt $command, EngineOptions $options): ?ProcessResult
    {
        switch ($diagnosis->kind) {
            case Diagnosis::NETWORK:
                $this->listener->notice('info', 'Network problem - retrying in 5 seconds');
                sleep(5);
                return $this->runComposer($command, $options);

            case Diagnosis::MEMORY:
                $this->listener->notice('info', 'Retrying with COMPOSER_MEMORY_LIMIT=-1');
                return $this->runComposer($command->withEnv(['COMPOSER_MEMORY_LIMIT' => '-1']), $options);

            case Diagnosis::SCAFFOLD_PERMISSIONS:
                $this->listener->notice('info', 'Making sites/default writable and finishing with composer install');
                $this->makeSitesDefaultWritable();
                return $this->context->composer->install();

            case Diagnosis::PLUGIN_BLOCKED:
                $plugin = $diagnosis->blockers[0] ?? null;
                if ($plugin === null || !in_array($plugin, $this->context->config->list('upgrade.trusted_plugins'), true)) {
                    return null;
                }
                $this->listener->notice('info', "Allowing trusted Composer plugin {$plugin} and retrying");
                $allow = $this->context->composer->run(['config', '--no-plugins', "allow-plugins.{$plugin}", 'true']);
                return $allow->successful() ? $this->runComposer($command, $options) : $allow;
        }
        return null;
    }

    private function rollback(FileSnapshot $snapshot, string $output): void
    {
        $vendorTouched = $snapshot->lockChanged() || str_contains($output, 'Package operations:');
        $snapshot->restore();
        if (!$vendorTouched) {
            return;
        }
        $this->listener->notice('info', 'Rolled back composer.json/composer.lock; re-syncing vendor with composer install');
        $install = $this->context->composer->install();
        if (!$install->successful()) {
            $this->listener->notice('warn', 'composer install after rollback failed - run it manually: ' . FailureAnalyzer::summary($install->combined()));
        }
    }

    private function makeSitesDefaultWritable(): void
    {
        $dir = DrupalProjectDetector::docroot($this->context->root()) . '/sites/default';
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, (fileperms($dir) & 0777) | 0700);
        foreach (['default.settings.php', 'default.services.yml', 'settings.php', 'services.yml'] as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                @chmod($path, (fileperms($path) & 0777) | 0600);
            }
        }
    }

    private function installedVersion(Step $step): ?string
    {
        if ($step->isCore()) {
            return $this->context->project->lockedVersion('drupal/core') ?? $this->context->project->installedCoreVersion();
        }
        return $step->package === null ? null : $this->context->project->lockedVersion($step->package);
    }

    // MARK: - After the run

    private function postUpdate(RunResult $result, EngineOptions $options): void
    {
        if (!$result->changedCode()) {
            return;
        }
        $drush = $this->context->drush;
        if ($drush === null) {
            $result->warnings[] = 'Run database updates (drush updatedb or /update.php) and clear caches.';
            $this->listener->notice('warn', end($result->warnings));
            return;
        }
        if (!$this->drupalBoots && !$drush->canBootstrap()) {
            $result->warnings[] = 'Drush cannot bootstrap Drupal - run `drush updatedb && drush cache:rebuild` once the database is running.';
            $this->listener->notice('warn', end($result->warnings));
            return;
        }

        $commands = $options->postUpdate;
        if ($options->exportConfig && !in_array('config:export', $commands, true)) {
            $commands[] = 'config:export';
        }
        foreach ($commands as $command) {
            $run = $drush->runNamed($command);
            $result->postUpdate[$command] = $run->successful();
            if ($run->successful()) {
                $this->listener->notice('ok', "drush {$command}");
                continue;
            }
            $this->listener->notice('fail', "drush {$command} failed: " . FailureAnalyzer::summary($run->combined()));
            if (str_starts_with($command, 'updatedb') && $result->snapshot?->databaseDump() !== null) {
                $this->listener->notice('warn', 'Restore code and database with `druid rollback --with-db` if the site is broken.');
            }
        }
    }
}
