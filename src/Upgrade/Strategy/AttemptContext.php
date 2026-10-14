<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Composer\ComposerProject;
use Druid\Composer\ComposerRunner;
use Druid\Config\Config;
use Druid\Scan\ScanResult;
use Druid\Upgrade\CommandBuilder;
use Druid\Upgrade\Diagnosis;

/**
 * What strategies can see while a step is being worked on: project, runner, scan, and the last failure.
 */
final class AttemptContext
{
    public ?string $lastOutput = null;
    public ?Diagnosis $lastDiagnosis = null;

    /** @var list<Diagnosis> */
    public array $diagnoses = [];

    /** @var list<string> */
    public array $outputs = [];

    public function __construct(
        public readonly ComposerProject $project,
        public readonly ComposerRunner $composer,
        public readonly CommandBuilder $builder,
        public readonly Config $config,
        public readonly ?ScanResult $scan = null,
    ) {
    }

    public function recordFailure(string $output, Diagnosis $diagnosis): void
    {
        $this->lastOutput = $output;
        $this->lastDiagnosis = $diagnosis;
        $this->diagnoses[] = $diagnosis;
        $this->outputs[] = $output;
    }

    public function sawKind(string $kind): bool
    {
        foreach ($this->diagnoses as $diagnosis) {
            if ($diagnosis->kind === $kind) {
                return true;
            }
        }
        return false;
    }

    /**
     * Blocking packages seen in any failure so far.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $all = [];
        foreach ($this->diagnoses as $diagnosis) {
            $all = array_merge($all, $diagnosis->blockers);
        }
        return array_values(array_unique($all));
    }
}
