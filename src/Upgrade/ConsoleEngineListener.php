<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Output\DruidStyle;
use Druid\Output\Symbols;
use Druid\Plan\Step;
use Druid\Plan\UpgradePlan;

/**
 * Prints engine progress as coloured, numbered steps.
 */
final class ConsoleEngineListener implements EngineListener
{
    private const STRATEGY_LABELS = [
        'isolated' => 'isolated update',
        'require_bump' => 'raise constraint',
        'blockers' => 'update blockers together',
        'all_dependencies' => 'all dependencies',
        'no_audit_blocking' => 'bypass audit blocking',
        'core_first' => 'core first',
        'step_down' => 'step down',
        'lenient' => 'lenient',
    ];

    public function __construct(private readonly DruidStyle $io)
    {
    }

    public function runStarted(UpgradePlan $plan): void
    {
        $this->io->heading(sprintf('Upgrading @%s - %s scope, %d step(s)', $plan->site, $plan->scope, count($plan->steps)));
    }

    public function notice(string $level, string $message): void
    {
        match ($level) {
            'ok' => $this->io->okLine($message),
            'warn' => $this->io->warnLine($message),
            'fail' => $this->io->failLine($message),
            default => $this->io->infoLine($message),
        };
    }

    public function stepStarted(int $number, int $total, Step $step): void
    {
        $this->io->stepLine($number, $total, $step->title);
    }

    public function attemptStarted(int $number, Attempt $attempt, string $display): void
    {
        $label = self::STRATEGY_LABELS[$attempt->strategy] ?? $attempt->strategy;
        $this->io->arrowLine(sprintf('Attempt %d: %s', $number, $label) . ($attempt->description !== '' ? " - {$attempt->description}" : ''));
        $this->io->commandLine($display, 6);
    }

    public function output(string $buffer, bool $isError): void
    {
        foreach (preg_split('/\R/', rtrim($buffer)) ?: [] as $line) {
            if (trim($line) !== '') {
                $this->io->mutedLine($line, 8);
            }
        }
    }

    public function attemptFailed(Diagnosis $diagnosis, string $output): void
    {
        $this->io->writeln('      <fail>' . Symbols::FAIL . '</fail> ' . DruidStyle::escape($diagnosis->summary) . ' <muted>(' . $diagnosis->kind . ')</muted>');
        if ($this->io->isVerboseOutput()) {
            foreach (array_slice(preg_split('/\R/', trim($output)) ?: [], -15) as $line) {
                $this->io->mutedLine($line, 8);
            }
        }
    }

    public function stepFinished(StepResult $result): void
    {
        $step = $result->step;
        switch ($result->status) {
            case StepResult::UPGRADED:
                $achieved = $result->achieved ?? $step->to ?? '?';
                $change = $step->from === null ? "installed {$achieved}" : "{$step->from} " . Symbols::ARROW . " {$achieved}";
                $this->io->okLine(($step->package ?? $step->title) . ' ' . $change . ($result->strategy !== null ? " (via {$result->strategy})" : ''));
                break;
            case StepResult::SATISFIED:
                $this->io->okLine($result->message);
                break;
            case StepResult::DONE:
                $this->io->okLine($result->message !== '' ? $result->message : 'Done');
                break;
            case StepResult::SKIPPED:
                $this->io->writeln('  <muted>' . Symbols::SKIP . ' ' . DruidStyle::escape($result->message) . '</muted>');
                break;
            default:
                $this->io->writeln(sprintf('  <warn>%s Deferred after %d attempt(s):</warn> %s', Symbols::WARN, $result->attempts, DruidStyle::escape($result->message)));
                foreach ($result->diagnosis?->hints ?? [] as $hint) {
                    $this->io->mutedLine(Symbols::BULLET . ' ' . $hint, 6);
                }
                foreach ($result->diagnosis?->suggestedCommands ?? [] as $command) {
                    $this->io->commandLine($command, 6);
                }
        }
        foreach ($result->warnings as $warning) {
            $this->io->warnLine($warning);
        }
    }

    public function runFinished(RunResult $result): void
    {
        $this->io->heading('Summary');
        if ($result->fatal !== null) {
            $this->io->failLine($result->fatal);
            return;
        }
        $counts = [];
        foreach ($result->steps as $step) {
            $counts[$step->status] = ($counts[$step->status] ?? 0) + 1;
        }
        $this->io->keyValue('Upgraded', DruidStyle::count($counts[StepResult::UPGRADED] ?? 0, 'ok'));
        $this->io->keyValue('Already current', (string) ($counts[StepResult::SATISFIED] ?? 0));
        $this->io->keyValue('Other steps done', (string) (($counts[StepResult::DONE] ?? 0) + ($counts[StepResult::SKIPPED] ?? 0)));
        $this->io->keyValue('Deferred', DruidStyle::count($counts[StepResult::DEFERRED] ?? 0, 'warn'));
        if ($result->snapshot !== null) {
            $this->io->keyValue('Snapshot', $result->snapshot->id);
        }

        $deferred = $result->deferred();
        if ($deferred !== []) {
            $this->io->newLine();
            $this->io->warnLine('Deferred steps (the rest of the site was upgraded):');
            foreach ($deferred as $item) {
                $this->io->mutedLine(Symbols::BULLET . ' ' . ($item->step->package ?? $item->step->title) . ': ' . $item->message, 4);
            }
            $this->io->infoLine('Re-run `druid plan` to see what is left, or `druid upgrade:module <name> -v` for full Composer output.');
        } elseif ($result->steps !== []) {
            $this->io->okLine('All steps completed.');
        }
    }
}
