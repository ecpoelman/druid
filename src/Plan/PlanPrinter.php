<?php

declare(strict_types=1);

namespace Druid\Plan;

use Druid\Composer\ComposerRunner;
use Druid\Output\DruidStyle;
use Druid\Output\Symbols;
use Druid\Security\VersionResolver;
use Druid\Upgrade\Attempt;
use Druid\Upgrade\CommandBuilder;

/**
 * Prints an upgrade plan as numbered, colour-coded steps with the exact commands.
 */
final class PlanPrinter
{
    private const STRATEGY_LABELS = [
        'isolated' => 'isolated update',
        'require_bump' => 'raise constraint',
        'blockers' => 'update blockers together',
        'all_dependencies' => 'all dependencies (-W)',
        'no_audit_blocking' => 'bypass audit blocking',
        'core_first' => 'core first',
        'step_down' => 'step down to an earlier safe release',
        'lenient' => 'lenient allow-list',
    ];

    /**
     * @param list<string> $strategies
     * @param list<string> $postUpdate
     */
    public function __construct(
        private readonly CommandBuilder $builder,
        private readonly ComposerRunner $composer,
        private readonly array $strategies,
        private readonly bool $bypassAuditBlocking,
        private readonly array $postUpdate,
        private readonly bool $hasDrush,
    ) {
    }

    public function print(UpgradePlan $plan, DruidStyle $io): void
    {
        $io->heading(sprintf('Upgrade plan for @%s - %s scope', $plan->site, $plan->scope));

        if ($plan->isEmpty()) {
            $io->okLine('Nothing to upgrade in this scope.');
            $this->printNotes($plan, $io);
            return;
        }

        $total = count($plan->steps);
        foreach ($plan->steps as $index => $step) {
            $io->stepLine($index + 1, $total, $step->title);
            $badges = [self::scopeBadge($step), 'risk: ' . self::risk($step->risk)];
            if ($step->severity->rank() > 1) {
                array_unshift($badges, DruidStyle::severity($step->severity, ' ' . strtoupper($step->severity->label()) . ' '));
            }
            $io->writeln('      ' . implode('  ', $badges));
            if ($step->description !== '') {
                $io->mutedLine($step->description, 6);
            }
            foreach ($this->commandsFor($step) as $command) {
                $io->commandLine($command, 6);
            }
        }

        if ($this->strategies !== []) {
            $labels = array_map(static fn (string $s): string => self::STRATEGY_LABELS[$s] ?? $s, $this->strategies);
            $io->newLine();
            $io->infoLine('If a step fails, druid keeps trying, in order: ' . implode(' ' . Symbols::ARROW . ' ', $labels) . '.');
            $io->mutedLine('Each attempt is rolled back before the next; a step that still fails is deferred with a diagnosis and the run continues.', 4);
        }

        if ($this->postUpdate !== []) {
            $io->newLine();
            if ($this->hasDrush) {
                $io->infoLine('After the Composer steps: ' . implode(', ', array_map(static fn (string $c): string => "drush {$c}", $this->postUpdate)));
            } else {
                $io->warnLine('Drush is not installed - run database updates (update.php) yourself after upgrading.');
            }
        }
        $this->printNotes($plan, $io);
    }

    /**
     * Shell commands for the first attempt of a step.
     *
     * @return list<string>
     */
    public function commandsFor(Step $step): array
    {
        return match ($step->kind) {
            Step::LENIENT => [
                'composer require mglaman/composer-drupal-lenient (when missing)',
                'composer config --json extra.drupal-lenient.allowed-list ' . json_encode($step->packages, JSON_UNESCAPED_SLASHES) . ' (merged with the existing list)',
            ],
            Step::READINESS => ['drush upgrade_status:checkstyle --all (when upgrade_status is installed)'],
            Step::RECTOR => ['vendor/bin/rector process ' . implode(' ', $step->packages) . ' (drupal-rector; --dry-run unless --rector)'],
            Step::INFO_FILES => array_map(
                static fn (string $file): string => "edit {$file}: core_version_requirement += ^" . VersionResolver::major((string) $step->to),
                $step->packages,
            ),
            default => $this->attemptCommands($this->builder->isolated($step)->withEnv(CommandBuilder::baseEnv($step, $this->bypassAuditBlocking))),
        };
    }

    /**
     * @return list<string>
     */
    private function attemptCommands(Attempt $attempt): array
    {
        $commands = [];
        foreach ($attempt->prerequisites as $prerequisite) {
            $commands = array_merge($commands, $this->attemptCommands($prerequisite));
        }
        $commands[] = $this->composer->commandLine($attempt->args, $attempt->env)->display();
        return $commands;
    }

    private function printNotes(UpgradePlan $plan, DruidStyle $io): void
    {
        foreach ($plan->notes as $note) {
            $io->warnLine($note);
        }
    }

    private static function scopeBadge(Step $step): string
    {
        return match ($step->scope) {
            Step::SCOPE_SECURITY => '<high>security</high>',
            Step::SCOPE_MAJOR => '<moderate>major</moderate>',
            default => '<low>minor</low>',
        };
    }

    private static function risk(string $risk): string
    {
        return match ($risk) {
            'high' => '<high>high</high>',
            'medium' => '<moderate>medium</moderate>',
            default => '<ok>low</ok>',
        };
    }
}
