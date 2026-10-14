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
     * Full deprecation scan of every extension. Null when upgrade_status is missing or the scan failed.
     */
    public function analyze(): ?UpgradeStatusReport
    {
        $drush = $this->context->drush;
        if ($drush === null || !$this->isInstalled()) {
            return null;
        }
        if (!$this->isEnabled() && !$this->enable()->successful()) {
            return null;
        }

        $checkstyle = $drush->run(['upgrade_status:checkstyle', '--all'], null, 3600);
        $issues = CheckstyleParser::parse($checkstyle->output);
        if ($issues !== null) {
            return new UpgradeStatusReport($issues, $checkstyle->output);
        }

        $analyze = $drush->run(['upgrade_status:analyze', '--all'], null, 3600);
        if (!$analyze->successful() && trim($analyze->output) === '') {
            return null;
        }
        return new UpgradeStatusReport([], $analyze->combined(), false);
    }
}
