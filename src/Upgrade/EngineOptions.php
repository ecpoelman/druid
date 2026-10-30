<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Config\Config;

/**
 * Run-time switches for the upgrade engine.
 */
final class EngineOptions
{
    /**
     * @param list<string> $postUpdate drush commands to run after the Composer steps
     */
    public function __construct(
        public readonly bool $backup = true,
        public readonly bool $verify = true,
        public readonly array $postUpdate = ['updatedb', 'cache:rebuild'],
        public readonly bool $exportConfig = false,
        public readonly bool $bypassAuditBlockingForSecurity = true,
        public readonly int $maxAttemptsPerStep = 14,
        public readonly bool $rector = false,
        public readonly bool $streamOutput = false,
        public readonly bool $batch = true,
    ) {
    }

    public static function fromConfig(Config $config, bool $backup, bool $streamOutput, bool $rector = false): self
    {
        return new self(
            $backup && $config->bool('upgrade.backup', true),
            $config->bool('upgrade.verify', true),
            $config->list('upgrade.post_update'),
            $config->bool('upgrade.export_config', false),
            $config->bool('upgrade.security_bypass_audit_blocking', true),
            (int) $config->get('upgrade.max_attempts', 14),
            $rector,
            $streamOutput,
            $config->bool('upgrade.batch', true),
        );
    }
}
