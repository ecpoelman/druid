<?php

declare(strict_types=1);

namespace Druid\Config;

/**
 * Merged druid configuration with dot-notation access, e.g. `upgrade.backup`.
 */
final class Config
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * Built-in defaults. Every key here can be overridden in ~/.druid/druid.yml or <project>/druid.yml.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // auto | native | ddev | lando
            'environment' => 'auto',
            'discovery' => [
                'paths' => ['~/Sites'],
                'herd' => true,
                'ddev' => true,
                'drush_aliases' => true,
            ],
            'scan' => [
                'drush_security' => true,
                'check_enabled' => true,
                'release_history' => true,
            ],
            'upgrade' => [
                // Database dump with `drush sql:dump` before changing anything.
                'backup' => true,
                // Strategy ladder, tried in order for every step until one works.
                'strategies' => [
                    'isolated',
                    'require_bump',
                    'blockers',
                    'all_dependencies',
                    'no_audit_blocking',
                    'core_first',
                    'step_down',
                    'lenient',
                ],
                // Security updates bypass Composer's insecure-version blocking from the first attempt.
                'security_bypass_audit_blocking' => true,
                'step_down_attempts' => 3,
                // Upper bound on Composer attempts for a single step across all strategies.
                'max_attempts' => 14,
                // Contrib steps first move together in one Composer run; only what fails goes step by step.
                'batch' => true,
                // Composer plugins druid may add to allow-plugins when an upgrade pulls them in.
                'trusted_plugins' => [
                    'composer/installers',
                    'cweagans/composer-patches',
                    'dealerdirect/phpcodesniffer-composer-installer',
                    'drupal/core-composer-scaffold',
                    'drupal/core-project-message',
                    'drupal/core-vendor-hardening',
                    'mglaman/composer-drupal-lenient',
                    'oomphinc/composer-installers-extender',
                    'php-http/discovery',
                    'phpstan/extension-installer',
                    'symfony/runtime',
                    'tbachert/spi',
                ],
                // After each step: composer validate + drush cache:rebuild to prove the site still boots.
                'verify' => true,
                // After the run: drush commands executed in order.
                'post_update' => ['updatedb', 'cache:rebuild'],
                'export_config' => false,
                'composer_timeout' => 1800,
                'rescan' => true,
            ],
            'packages' => [
                'exclude' => [],
                'pin' => [],
            ],
            'cache' => [
                'ttl' => 3600,
            ],
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key, []);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(self::merge($this->data, $overrides));
    }

    /**
     * Deep merge: associative arrays merge recursively, lists are replaced.
     *
     * @param array<mixed> $base
     * @param array<mixed> $override
     * @return array<mixed>
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
