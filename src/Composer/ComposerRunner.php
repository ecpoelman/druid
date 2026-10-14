<?php

declare(strict_types=1);

namespace Druid\Composer;

use Druid\Environment\CommandLine;
use Druid\Environment\ExecutionEnvironment;
use Druid\Environment\ProcessResult;
use Druid\Environment\ProcessRunner;
use Druid\Security\VersionResolver;

/**
 * Runs Composer for one project through its execution environment.
 */
final class ComposerRunner
{
    private ?string $version = null;

    /** @var array<string, list<string>> */
    private array $releases = [];

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ExecutionEnvironment $environment,
        private readonly string $root,
        private readonly float $timeout = 1800.0,
    ) {
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @param (callable(string, bool): void)|null $onOutput
     */
    public function run(array $args, array $env = [], ?callable $onOutput = null, ?float $timeout = null): ProcessResult
    {
        $line = $this->commandLine($args, $env);
        return $this->runner->run($line->command, $this->root, $line->env, $onOutput, $timeout ?? $this->timeout);
    }

    /**
     * The exact command that run() would execute, for display and planning.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public function commandLine(array $args, array $env = []): CommandLine
    {
        foreach (['--no-interaction', '--no-ansi'] as $flag) {
            if (!in_array($flag, $args, true)) {
                $args[] = $flag;
            }
        }
        return $this->environment->composer($args, $env);
    }

    /**
     * Runs a Composer command that prints JSON. Composer exits non-zero for audit findings,
     * so stdout is parsed regardless of the exit code.
     *
     * @param list<string> $args
     * @return array<mixed>|null
     */
    public function json(array $args, float $timeout = 300.0): ?array
    {
        $result = $this->run($args, [], null, $timeout);
        return self::decodeJson($result->output);
    }

    /**
     * @return array<mixed>|null
     */
    public static function decodeJson(string $output): ?array
    {
        $start = strpos($output, '{');
        $startList = strpos($output, '[');
        if ($start === false || ($startList !== false && $startList < $start)) {
            $start = $startList;
        }
        if ($start === false) {
            return null;
        }
        $data = json_decode(substr($output, $start), true);
        return is_array($data) ? $data : null;
    }

    public function version(): ?string
    {
        if ($this->version === null) {
            $result = $this->run(['--version'], [], null, 60);
            $this->version = preg_match('/Composer (?:version )?v?(\d+\.\d+\.\d+)/', $result->combined(), $m) === 1 ? $m[1] : '';
        }
        return $this->version === '' ? null : $this->version;
    }

    /**
     * `--minimal-changes` (Composer 2.7+) keeps unrelated transitive packages at their locked versions.
     */
    public function supportsMinimalChanges(): bool
    {
        $version = $this->version();
        return $version !== null && version_compare($version, '2.7.0', '>=');
    }

    /**
     * `composer audit` against composer.lock.
     *
     * @return array{advisories: array<string, list<array<string, mixed>>>, abandoned: array<string, string|null>}
     */
    public function audit(): array
    {
        $data = $this->json(['audit', '--format=json', '--locked'])
            ?? $this->json(['audit', '--format=json'])
            ?? [];

        $advisories = [];
        foreach ((array) ($data['advisories'] ?? []) as $package => $items) {
            // Composer emits a list per package, or an id-keyed object in some versions.
            $advisories[(string) $package] = array_values(array_filter((array) $items, 'is_array'));
        }
        $abandoned = [];
        foreach ((array) ($data['abandoned'] ?? []) as $package => $replacement) {
            $abandoned[(string) $package] = is_string($replacement) ? $replacement : null;
        }
        return ['advisories' => $advisories, 'abandoned' => $abandoned];
    }

    /**
     * `composer outdated --direct` keyed by package.
     * With `$minorOnly`, `latest` is the newest release compatible with the installed major.
     *
     * @return array<string, array{version: string, latest: string, status: string}>
     */
    public function outdated(bool $minorOnly = false): array
    {
        $args = ['outdated', '--direct', '--format=json'];
        if ($minorOnly) {
            $args[] = '--minor-only';
        }
        $data = $this->json(array_merge($args, ['--locked'])) ?? $this->json($args) ?? [];

        $map = [];
        foreach ((array) ($data['installed'] ?? $data['locked'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['name'])) {
                continue;
            }
            $map[(string) $item['name']] = [
                'version' => ltrim((string) ($item['version'] ?? ''), 'v'),
                'latest' => ltrim((string) ($item['latest'] ?? ''), 'v'),
                'status' => (string) ($item['latest-status'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * Every published version of a package (`composer show --all`), newest first, dev branches removed.
     *
     * @return list<string>
     */
    public function releases(string $package): array
    {
        if (!isset($this->releases[$package])) {
            $data = $this->json(['show', $package, '--all', '--format=json'], 120);
            $versions = array_map(
                static fn ($v): string => ltrim((string) $v, 'v'),
                (array) ($data['versions'] ?? []),
            );
            $versions = array_values(array_filter($versions, static fn (string $v): bool => VersionResolver::isRelease($v)));
            $this->releases[$package] = VersionResolver::sort($versions, true);
        }
        return $this->releases[$package];
    }

    public function whyNot(string $package, string $version): ProcessResult
    {
        return $this->run(['why-not', $package, $version], [], null, 300);
    }

    public function validate(): ProcessResult
    {
        return $this->run(['validate', '--no-check-publish', '--no-check-all'], [], null, 120);
    }

    /**
     * @param (callable(string, bool): void)|null $onOutput
     */
    public function install(?callable $onOutput = null): ProcessResult
    {
        return $this->run(['install'], [], $onOutput);
    }
}
