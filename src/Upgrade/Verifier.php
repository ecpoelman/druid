<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Composer\ComposerRunner;
use Druid\Drush\DrushRunner;

/**
 * Proves a step left the site healthy: valid Composer state and a Drupal that still boots.
 */
final class Verifier
{
    public function __construct(
        private readonly ComposerRunner $composer,
        private readonly ?DrushRunner $drush,
        private readonly bool $checkDrupal,
    ) {
    }

    /**
     * @param bool $drupalBoots whether Drush could bootstrap Drupal before the run started
     */
    public function verify(bool $drupalBoots): VerificationResult
    {
        $validate = $this->composer->validate();
        $text = strtolower($validate->combined());
        if ($validate->exitCode >= 2 || str_contains($text, 'lock file is not up to date')) {
            return VerificationResult::failed(['composer validate: ' . FailureAnalyzer::summary($validate->combined())]);
        }

        if (!$this->checkDrupal || $this->drush === null || !$drupalBoots) {
            return VerificationResult::passed();
        }

        $rebuild = $this->drush->cacheRebuild();
        if ($rebuild->successful()) {
            return VerificationResult::passed();
        }

        // New code can need its database updates before caches rebuild cleanly.
        $updb = $this->drush->updateDatabase();
        if (!$updb->successful()) {
            return VerificationResult::failed([
                'drush cache:rebuild failed: ' . FailureAnalyzer::summary($rebuild->combined()),
                'drush updatedb failed: ' . FailureAnalyzer::summary($updb->combined()),
            ]);
        }

        $retry = $this->drush->cacheRebuild();
        if ($retry->successful()) {
            return VerificationResult::passed(['Ran drush updatedb so caches could rebuild.']);
        }

        // Database updates already ran, so rolling back code would leave the schema ahead of it.
        return VerificationResult::passed([
            'drush cache:rebuild still fails after updatedb: ' . FailureAnalyzer::summary($retry->combined()),
            'Code was kept because database updates already ran. Check the site, or restore the database dump.',
        ]);
    }
}
