<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Composer\ComposerProject;
use Druid\Composer\ProblemParser;
use Druid\Drupal\PhpRequirements;
use Druid\Plan\Step;
use Druid\Security\VersionResolver;

/**
 * Classifies Composer failures and produces hints, manual commands and blocking packages.
 */
final class FailureAnalyzer
{
    public function __construct(private readonly ComposerProject $project)
    {
    }

    public function analyze(string $output, Step $step): Diagnosis
    {
        $lower = strtolower($output);
        $problems = ProblemParser::distill(ProblemParser::parse($output));
        $advisories = ProblemParser::advisoryIds($output);
        $ignore = array_filter([$this->project->name(), $step->package]);
        $blockers = ProblemParser::blockers($output, $ignore);
        $summary = self::summary($output);
        $package = (string) $step->package;
        $target = (string) $step->to;

        $hints = [];
        $commands = [];

        if (self::containsAny($lower, ['connection timeout', 'curl error 28', 'could not resolve host', 'operation timed out', 'failed to open stream: http', 'network is unreachable'])) {
            return new Diagnosis(Diagnosis::NETWORK, $summary, [
                'Composer could not reach Packagist or packages.drupal.org. Check your network, VPN or proxy.',
                'druid retries once automatically; run the upgrade again when the connection is back.',
            ], ['composer clear-cache'], $advisories, $problems, $blockers);
        }

        if (str_contains($lower, 'allowed memory size')) {
            return new Diagnosis(Diagnosis::MEMORY, $summary, [
                'Composer ran out of memory. druid retries with COMPOSER_MEMORY_LIMIT=-1.',
            ], ['COMPOSER_MEMORY_LIMIT=-1 composer ...'], $advisories, $problems, $blockers);
        }

        $plugin = self::blockedPlugin($output);
        if ($plugin !== null) {
            return new Diagnosis(Diagnosis::PLUGIN_BLOCKED, "{$plugin} is a Composer plugin that allow-plugins does not permit yet", [
                "The new release pulls in {$plugin}, which runs code during Composer operations.",
                'druid allows plugins listed under upgrade.trusted_plugins automatically; others need your decision.',
            ], ["composer config --no-plugins allow-plugins.{$plugin} true"], $advisories, $problems, [$plugin]);
        }

        if ((str_contains($lower, 'could not delete') || str_contains($lower, 'permission denied')) && (str_contains($lower, 'default.settings.php') || str_contains($lower, 'sites/default'))) {
            return new Diagnosis(Diagnosis::SCAFFOLD_PERMISSIONS, $summary, [
                'Drupal scaffold could not replace files in sites/default - the folder is usually read-only (hardened).',
                'druid makes sites/default writable and re-runs composer install to finish scaffolding.',
            ], ['chmod u+w web/sites/default && composer install'], $advisories, $problems, $blockers);
        }

        if (self::containsAny($lower, ['cannot apply patch', 'could not apply patch', 'failed to apply patch'])) {
            return new Diagnosis(Diagnosis::PATCH_FAILED, $summary, [
                'A patch from composer.json / composer.patches.json no longer applies to the new release.',
                'The fix is often already included upstream: check the issue and remove the patch, or reroll it.',
            ], [], $advisories, $problems, $blockers);
        }

        if (str_contains($lower, 'your php version') || preg_match('/requires php [^\n]*does not satisfy/', $lower) === 1) {
            $major = $step->isCore() && $target !== '' ? VersionResolver::major($target) : null;
            $pins = $major !== null ? PhpRequirements::problems($major, null, $this->project) : [];
            $platform = PhpRequirements::platformPhp($this->project);
            $hints = $pins !== [] ? $pins : [
                $platform !== null
                    ? "The target release needs a newer PHP than composer.json's config.platform.php ({$platform})."
                    : 'The target release needs a newer PHP than the one running Composer.',
                'Raise the PHP version (Herd: `herd isolate 8.3`; DDEV: php_version in .ddev/config.yaml), or stay on an earlier release.',
            ];
            $commands = $major !== null ? PhpRequirements::fixCommands($major, $this->project) : [];
            return new Diagnosis(Diagnosis::PHP_REQUIREMENT, $pins[0] ?? $summary, $hints, $commands, $advisories, $problems, $blockers);
        }

        $contribBlockers = array_values(array_filter($blockers, static fn (string $b): bool => str_starts_with($b, 'drupal/') && !ComposerProject::isCorePackage($b)));
        if ($step->kind === Step::MAJOR_CORE && $contribBlockers !== [] && str_contains($lower, 'drupal/core')) {
            return new Diagnosis(Diagnosis::CONTRIB_BLOCKS_CORE, $summary, [
                'Some contrib releases do not declare support for the target core yet: ' . implode(', ', $contribBlockers) . '.',
                'druid can update them together with core, or allow them through the composer-drupal-lenient plugin.',
            ], ['composer require mglaman/composer-drupal-lenient', 'composer config --json --merge extra.drupal-lenient.allowed-list \'' . json_encode($contribBlockers, JSON_UNESCAPED_SLASHES) . '\''], $advisories, $problems, $contribBlockers);
        }

        if (self::containsAny($lower, ['block-insecure', 'affected by security advisories']) || (str_contains($lower, 'could not be resolved') && $advisories !== [])) {
            $hints[] = 'Composer refused versions affected by security advisories while resolving dependencies.';
            $hints[] = 'druid retries with audit blocking bypassed (COMPOSER_NO_SECURITY_BLOCKING=1) because it targets a known fixed release.';
            if ($step->isCore()) {
                $hints[] = 'Locked contrib modules listed in Problems are expected until core reaches the fixed release.';
            }
            $commands[] = 'COMPOSER_NO_SECURITY_BLOCKING=1 composer update ' . ($step->isCore() ? implode(' ', $this->project->coreRequireArguments($target)) : "{$package}:{$target}") . ' --with-all-dependencies';
            return new Diagnosis(Diagnosis::AUDIT_BLOCKED, $summary, $hints, $commands, $advisories, $problems, $blockers);
        }

        $needsCore = !$step->isCore()
            && (str_contains($lower, 'could not be resolved') || str_contains($lower, 'keeping locked at old version') || str_contains($lower, 'conflicts with'))
            && preg_match('/requires drupal\/core(-recommended)? /', $lower) === 1;
        if ($needsCore) {
            return new Diagnosis(Diagnosis::NEEDS_NEWER_CORE, $summary, [
                "The new {$step->package} release most likely needs a newer Drupal core than the one installed.",
                'druid updates core first (within the current minor) and retries; you can also run `druid upgrade:core` first.',
            ], ["composer update {$package}:{$target} --with-all-dependencies"], $advisories, $problems, $blockers);
        }

        if (self::containsAny($lower, ['root requirement', 'keeping locked at old version', 'with-all-dependencies'])) {
            return new Diagnosis(Diagnosis::ROOT_DEPENDENCIES, $summary, [
                'A root package (listed in composer.json) has to move too. druid retries with --with-all-dependencies.',
            ], [$step->isCore() ? 'composer update drupal/core-recommended drupal/core-composer-scaffold --with-all-dependencies' : "composer update {$package}:{$target} --with-all-dependencies"], $advisories, $problems, $blockers);
        }

        if (str_contains($lower, 'could not be resolved') || str_contains($lower, 'your requirements could not be resolved')) {
            $hints[] = 'Constraints in composer.json conflict with the requested release.';
            if ($blockers !== []) {
                $hints[] = 'Blocking packages: ' . implode(', ', array_slice($blockers, 0, 6)) . '.';
            }
            $commands[] = "composer why-not {$package} {$target}";
            return new Diagnosis(Diagnosis::DEPENDENCY_CONFLICT, $summary, $hints, $commands, $advisories, $problems, $blockers);
        }

        return new Diagnosis(Diagnosis::UNKNOWN, $summary, ['Review the Composer output above for the first error (rerun with -v for full output).'], [], $advisories, $problems, $blockers);
    }

    /**
     * Package named in Composer's "contains a Composer plugin which is blocked by your allow-plugins config" error.
     */
    public static function blockedPlugin(string $output): ?string
    {
        $flat = preg_replace('/\s+/', ' ', $output) ?? $output;
        if (preg_match('#([a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*) contains a Composer plugin which is blocked#i', $flat, $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }

    /**
     * The most useful single line of Composer error output.
     */
    public static function summary(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $l): bool => $l !== ''));
        foreach ($lines as $line) {
            if (preg_match('/^(Your requirements could not be resolved|In [A-Za-z]+\.php|\[.*Exception\]|Fatal error|PHP Fatal)/', $line) === 1) {
                return $line;
            }
        }
        foreach ($lines as $line) {
            if (str_starts_with($line, '- ') && str_contains($line, 'requires')) {
                return ltrim($line, '- ');
            }
        }
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (!str_starts_with($lines[$i], 'Problem ') && !str_starts_with($lines[$i], 'Use the option')) {
                return $lines[$i];
            }
        }
        return 'Composer failed';
    }

    /**
     * @param list<string> $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
