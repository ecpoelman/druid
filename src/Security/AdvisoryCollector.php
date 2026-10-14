<?php

declare(strict_types=1);

namespace Druid\Security;

use Druid\Composer\ComposerRunner;
use Druid\Drush\DrushRunner;

/**
 * Gathers advisories from `composer audit` (Packagist + drupal.org advisories) and,
 * when available, `drush pm:security`.
 */
final class AdvisoryCollector
{
    /** @var array<string, string|null> */
    private array $abandoned = [];

    public function __construct(
        private readonly ComposerRunner $composer,
        private readonly ?DrushRunner $drush = null,
    ) {
    }

    /**
     * Advisories that apply to the installed versions, keyed by package.
     *
     * @param array<string, string> $installed package => installed version
     * @return array<string, list<Advisory>>
     */
    public function collect(array $installed): array
    {
        $audit = $this->composer->audit();
        $this->abandoned = $audit['abandoned'];

        $byPackage = [];
        foreach ($audit['advisories'] as $package => $items) {
            foreach ($items as $item) {
                $advisory = Advisory::fromComposerAudit($package, $item);
                $version = $installed[$package] ?? null;
                // composer audit only reports matches; re-check so stale ranges don't leak in.
                if ($version !== null && $advisory->affectedVersions !== null && !$advisory->affects($version)) {
                    continue;
                }
                $byPackage[$package][] = $advisory;
            }
        }

        if ($this->drush !== null) {
            foreach ($this->drush->securityFindings() as $package => $finding) {
                if (isset($byPackage[$package]) || $finding['min-version'] === '') {
                    continue;
                }
                $byPackage[$package][] = new Advisory(
                    'drush-pm-security',
                    $package,
                    'Drupal security release available (drush pm:security)',
                    Severity::Unknown,
                    '<' . $finding['min-version'],
                    null,
                    'https://www.drupal.org/security',
                    'drush',
                );
            }
        }

        ksort($byPackage);
        return $byPackage;
    }

    /**
     * Abandoned packages from the last collect(): package => suggested replacement.
     *
     * @return array<string, string|null>
     */
    public function abandoned(): array
    {
        return $this->abandoned;
    }
}
