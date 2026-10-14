<?php

declare(strict_types=1);

namespace Druid\Upgrade\Handler;

use Druid\Drupal\InfoFile;
use Druid\Plan\Step;
use Druid\Security\VersionResolver;
use Druid\Upgrade\EngineListener;
use Druid\Upgrade\StepHandler;
use Druid\Upgrade\StepResult;

/**
 * Adds the target Drupal major to core_version_requirement in custom modules and themes, so Drupal does
 * not mark them incompatible once core moves. Code deprecations are rector's and upgrade_status's job.
 */
final class InfoFileHandler implements StepHandler
{
    public function __construct(private readonly string $root)
    {
    }

    public function supports(Step $step): bool
    {
        return $step->kind === Step::INFO_FILES;
    }

    public function handle(Step $step, EngineListener $listener): StepResult
    {
        if ($step->to === null || $step->packages === []) {
            return new StepResult($step, StepResult::SKIPPED, message: 'No custom extensions to update.');
        }
        $major = VersionResolver::major($step->to);
        $currentMajor = $step->from !== null ? VersionResolver::major($step->from) : null;

        $changed = [];
        foreach ($step->packages as $relative) {
            $file = str_starts_with($relative, '/') ? $relative : rtrim($this->root, '/') . '/' . $relative;
            $requirement = InfoFile::declareSupport($file, $major, $currentMajor);
            if ($requirement !== null) {
                $changed[] = basename($file, '.info.yml');
                $listener->notice('info', "{$relative}: core_version_requirement: {$requirement}");
            }
        }
        if ($changed === []) {
            return new StepResult($step, StepResult::SATISFIED, message: "Custom extensions already declare Drupal {$major} support.");
        }
        return new StepResult($step, StepResult::DONE, attempts: 1, message: 'Declared Drupal ' . $major . ' support in ' . implode(', ', $changed) . ' - review with `git diff` and run `druid upgrade:check` for deprecated code.');
    }
}
