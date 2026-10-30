<?php

declare(strict_types=1);

namespace Druid\Major;

use Druid\Environment\ProcessResult;
use Druid\SiteContext;

/**
 * Drives drupal/upgrade_status through Drush: install, enable and analyze.
 */
final class UpgradeStatusRunner
{
    public const PACKAGE = 'drupal/upgrade_status';

    public function __construct(private readonly SiteContext $context)
    {
    }

    public function isInstalled(): bool
    {
        return $this->context->project->hasPackage(self::PACKAGE);
    }

    public function isEnabled(): bool
    {
        return $this->context->drush !== null && isset($this->context->drush->enabledExtensions()['upgrade_status']);
    }

    /**
     * Adds upgrade_status as a dev dependency and enables it.
     */
    public function install(): ProcessResult
    {
        if (!$this->isInstalled()) {
            $require = $this->context->composer->run(['require', '--dev', self::PACKAGE, '--with-all-dependencies']);
            if (!$require->successful()) {
                return $require;
            }
        }
        return $this->enable();
    }

    public function enable(): ProcessResult
    {
        if ($this->context->drush === null) {
            return new ProcessResult('drush pm:install upgrade_status', 127, '', 'Drush is not available for this site.');
        }
        return $this->context->drush->enable(['upgrade_status']);
    }

    /**
     * Deprecation scan of `$projects`, or of every extension when null (slow: phpstan runs per project).
     * Null when upgrade_status is missing or the scan failed.
     *
     * @param list<string>|null $projects
     */
    public function analyze(?array $projects = null): ?UpgradeStatusReport
    {
        $targets = $projects ?? ['--all'];
        $drush = $this->context->drush;
        if ($drush === null || !$this->isInstalled()) {
            return null;
        }
        if (!$this->isEnabled() && !$this->enable()->successful()) {
            return null;
        }

        $checkstyle = $drush->run(['upgrade_status:checkstyle', ...$targets], null, 3600);
        // One name upgrade_status doesn't know (e.g. a disabled theme) aborts the whole command.
        while ($projects !== null && preg_match('/project machine name (\S+) is invalid/', $checkstyle->combined(), $m) === 1 && in_array($m[1], $targets, true)) {
            $targets = array_values(array_diff($targets, [$m[1]]));
            if ($targets === []) {
                return new UpgradeStatusReport([], '');
            }
            $checkstyle = $drush->run(['upgrade_status:checkstyle', ...$targets], null, 3600);
        }
        $issues = CheckstyleParser::parse($checkstyle->output);
        if ($issues !== null) {
            return new UpgradeStatusReport($issues, $checkstyle->output);
        }

        $analyze = $drush->run(['upgrade_status:analyze', ...$targets], null, 3600);
        if (!$analyze->successful() && trim($analyze->output) === '') {
            return null;
        }
        return new UpgradeStatusReport([], $analyze->combined(), false);
    }
}
