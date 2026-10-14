<?php

declare(strict_types=1);

namespace Druid\Drush;

use Druid\Composer\ComposerRunner;
use Druid\Environment\CommandLine;
use Druid\Environment\ExecutionEnvironment;
use Druid\Environment\ProcessResult;
use Druid\Environment\ProcessRunner;

/**
 * Runs the project's Drush for Drupal-side work: status, security checks, database updates, caches, dumps.
 */
final class DrushRunner
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ExecutionEnvironment $environment,
        private readonly string $root,
        public readonly DrushInfo $info,
        private readonly ?string $uri = null,
    ) {
    }

    /**
     * @param list<string> $args
     * @param (callable(string, bool): void)|null $onOutput
     */
    public function run(array $args, ?callable $onOutput = null, float $timeout = 900.0): ProcessResult
    {
        $line = $this->commandLine($args);
        return $this->runner->run($line->command, $this->root, $line->env, $onOutput, $timeout);
    }

    /**
     * @param list<string> $args
     */
    public function commandLine(array $args): CommandLine
    {
        if ($this->uri !== null) {
            $args[] = '--uri=' . $this->uri;
        }
        $args[] = '--no-interaction';
        return $this->environment->drush($this->info->binary, $args);
    }

    /**
     * @param list<string> $args
     * @return array<mixed>|null
     */
    public function json(array $args, float $timeout = 300.0): ?array
    {
        $args[] = '--format=json';
        return ComposerRunner::decodeJson($this->run($args, null, $timeout)->output);
    }

    /**
     * `drush status` as an array, e.g. ['bootstrap' => 'Successful', 'drupal-version' => '10.6.11'].
     *
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        return $this->json(['status'], 120);
    }

    public function canBootstrap(): bool
    {
        return (($this->status() ?? [])['bootstrap'] ?? '') === 'Successful';
    }

    /**
     * Enabled modules and themes keyed by machine name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function enabledExtensions(): array
    {
        $data = $this->json(['pm:list', '--status=enabled'], 180) ?? [];
        return array_filter($data, 'is_array');
    }

    /**
     * Every module, profile and theme recorded as installed in core.extension, including ones whose code
     * has disappeared (e.g. a core module removed by a major upgrade).
     *
     * @return list<string>|null null when the config can't be read
     */
    public function installedExtensionNames(): ?array
    {
        $data = $this->json(['config:get', 'core.extension'], 60);
        if (!is_array($data) || !isset($data['module'])) {
            return null;
        }
        // pm:list never lists the install profile, so leave it out to keep the two comparable.
        $profile = is_string($data['profile'] ?? null) ? $data['profile'] : null;
        $names = array_merge(array_keys((array) $data['module']), array_keys((array) ($data['theme'] ?? [])));
        return array_values(array_unique(array_filter(
            array_map('strval', $names),
            static fn (string $name): bool => $name !== $profile,
        )));
    }

    /**
     * `drush pm:security` findings: package => ['version' => installed, 'min-version' => secure].
     *
     * @return array<string, array{version: string, min-version: string}>
     */
    public function securityFindings(): array
    {
        $data = $this->json(['pm:security'], 180) ?? [];
        $findings = [];
        foreach ($data as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? $key);
            if (!str_contains($name, '/') || !isset($row['version'])) {
                continue;
            }
            $findings[$name] = [
                'version' => ltrim((string) $row['version'], 'v'),
                'min-version' => ltrim((string) ($row['min-version'] ?? ''), 'v'),
            ];
        }
        return $findings;
    }

    /**
     * @param (callable(string, bool): void)|null $onOutput
     */
    public function updateDatabase(?callable $onOutput = null): ProcessResult
    {
        return $this->run(['updatedb', '--yes'], $onOutput, 3600);
    }

    public function cacheRebuild(): ProcessResult
    {
        return $this->run(['cache:rebuild'], null, 600);
    }

    public function configExport(): ProcessResult
    {
        return $this->run(['config:export', '--yes'], null, 600);
    }

    /**
     * Gzipped database dump. Drush appends `.gz` to the result file.
     */
    public function sqlDump(string $hostFile): ProcessResult
    {
        return $this->run(['sql:dump', '--gzip', '--result-file=' . $this->environment->containerPath($hostFile)], null, 3600);
    }

    /**
     * Restores a dump created by sqlDump().
     */
    public function sqlRestore(string $hostFile): ProcessResult
    {
        $drop = $this->run(['sql:drop', '--yes'], null, 600);
        if (!$drop->successful()) {
            return $drop;
        }
        return $this->run(['sql:query', '--file=' . $this->environment->containerPath($hostFile)], null, 3600);
    }

    /**
     * @param list<string> $extensions
     */
    public function enable(array $extensions): ProcessResult
    {
        return $this->run(array_merge(['pm:install', '--yes'], $extensions), null, 600);
    }

    /**
     * Runs a named post-update command from config, e.g. `updatedb` or `deploy`.
     */
    public function runNamed(string $command): ProcessResult
    {
        $args = preg_split('/\s+/', trim($command)) ?: [];
        $needsYes = in_array($args[0] ?? '', ['updatedb', 'updb', 'config:export', 'cex', 'config:import', 'cim', 'deploy', 'locale:update'], true);
        if ($needsYes && !in_array('--yes', $args, true) && !in_array('-y', $args, true)) {
            $args[] = '--yes';
        }
        return $this->run($args, null, 3600);
    }
}
