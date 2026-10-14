<?php

declare(strict_types=1);

namespace Druid\Major;

use Druid\Environment\ProcessResult;
use Druid\SiteContext;

/**
 * Runs palantirnet/drupal-rector against custom code to replace deprecated API calls.
 */
final class RectorRunner
{
    public const PACKAGE = 'palantirnet/drupal-rector';

    private const BUNDLED = ['vendor', 'node_modules'];

    public function __construct(private readonly SiteContext $context)
    {
    }

    public function isInstalled(): bool
    {
        return $this->context->project->hasPackage(self::PACKAGE);
    }

    public function install(): ProcessResult
    {
        return $this->context->composer->run(['require', '--dev', self::PACKAGE, '--with-all-dependencies']);
    }

    /**
     * Copies drupal-rector's sample rector.php into the project when none exists.
     */
    public function ensureConfig(): bool
    {
        $target = $this->context->root() . '/rector.php';
        if (is_file($target)) {
            return true;
        }
        $sample = $this->context->root() . '/vendor/' . self::PACKAGE . '/rector.php';
        return is_file($sample) && copy($sample, $target);
    }

    /**
     * @param list<string> $paths project-relative paths
     * @return list<string>
     */
    public function commandArgs(array $paths, bool $dryRun): array
    {
        $args = array_merge(['vendor/bin/rector', 'process'], self::withoutBundledCode($this->context->root(), $paths), ['--no-progress-bar']);
        if ($dryRun) {
            $args[] = '--dry-run';
        }
        return $args;
    }

    /**
     * Replaces extension directories that bundle third-party code (vendor/, node_modules/) with their other
     * entries, so rector only rewrites the site's own code.
     *
     * @param list<string> $paths project-relative paths
     * @return list<string>
     */
    public static function withoutBundledCode(string $root, array $paths): array
    {
        $expanded = [];
        foreach ($paths as $path) {
            $absolute = rtrim($root, '/') . '/' . $path;
            $entries = is_dir($absolute) ? (scandir($absolute) ?: []) : [];
            if (array_intersect(self::BUNDLED, $entries) === []) {
                $expanded[] = $path;
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..' && !in_array($entry, self::BUNDLED, true) && !str_starts_with($entry, '.')) {
                    $expanded[] = rtrim($path, '/') . '/' . $entry;
                }
            }
        }
        return $expanded;
    }

    /**
     * @param list<string> $paths
     */
    public function display(array $paths, bool $dryRun): string
    {
        return $this->context->environment->php($this->commandArgs($paths, $dryRun))->display();
    }

    /**
     * @param list<string> $paths project-relative paths
     */
    public function run(array $paths, bool $dryRun): ProcessResult
    {
        $line = $this->context->environment->php($this->commandArgs($paths, $dryRun));
        return $this->context->runner->run($line->command, $this->context->root(), $line->env, null, 3600);
    }
}
