<?php

declare(strict_types=1);

namespace Druid\Upgrade\Handler;

use Druid\Drupal\PhpRequirements;
use Druid\Plan\Step;
use Druid\Security\VersionResolver;
use Druid\Upgrade\EngineListener;
use Druid\Upgrade\StepHandler;
use Druid\Upgrade\StepResult;

/**
 * Raises php_version in hosting config (pantheon.yml, .ddev/config.yaml) to what the target Drupal major
 * needs, so the server runs it once the code is deployed. Composer's config.platform.php is raised by the
 * core step itself, so a failed core step rolls it back.
 */
final class PhpPlatformHandler implements StepHandler
{
    public function __construct(private readonly string $root)
    {
    }

    public function supports(Step $step): bool
    {
        return $step->kind === Step::PHP_PLATFORM;
    }

    public function handle(Step $step, EngineListener $listener): StepResult
    {
        $major = VersionResolver::major((string) $step->to);
        $version = PhpRequirements::shortMinimum($major);
        $changed = [];
        $warnings = [];

        foreach (array_keys(PhpRequirements::outdatedHostingPhp($major, $this->root)) as $file) {
            if (!PhpRequirements::setHostingPhp($this->root, $file, $version)) {
                $warnings[] = "Could not update php_version in {$file} - set it to {$version} by hand.";
                continue;
            }
            $changed[] = $file;
            $listener->notice('info', "{$file}: php_version: {$version}");
            if ($file === '.ddev/config.yaml') {
                $warnings[] = "Run `ddev restart` so the local site runs PHP {$version}.";
            }
        }

        if ($changed === []) {
            return new StepResult($step, $warnings === [] ? StepResult::SATISFIED : StepResult::DEFERRED, message: "Hosting config already runs PHP {$version}+.", warnings: $warnings);
        }
        return new StepResult($step, StepResult::DONE, attempts: 1, message: "Hosting PHP set to {$version} in " . implode(', ', $changed), warnings: $warnings);
    }
}
