<?php

declare(strict_types=1);

namespace Druid\Major;

use Druid\Site\DrupalProjectDetector;
use Druid\SiteContext;

/**
 * Deprecation analysis of custom code with phpstan-drupal and phpstan-deprecation-rules - the same engine
 * upgrade_status uses - for projects that have them (drupal/core-dev ships both) but not upgrade_status.
 */
final class PhpstanRunner
{
    /** Findings that break on the next major: removed functions/classes, changed signatures, deprecations. */
    private const BLOCKING = ['function.notFound', 'class.notFound', 'method.notFound', 'staticMethod.notFound', 'arguments.count', 'constant.notFound', 'classConstant.notFound'];

    public function __construct(private readonly SiteContext $context)
    {
    }

    public function isAvailable(): bool
    {
        $vendor = DrupalProjectDetector::vendorDir($this->context->root());
        return is_file($vendor . '/phpstan/phpstan/phpstan')
            && is_dir($vendor . '/mglaman/phpstan-drupal')
            && is_dir($vendor . '/phpstan/phpstan-deprecation-rules');
    }

    /**
     * @param array<string, string> $extensions name => absolute directory
     */
    public function analyze(array $extensions): ?UpgradeStatusReport
    {
        if ($extensions === [] || !$this->isAvailable()) {
            return null;
        }
        $root = $this->context->root();
        $config = $this->writeConfig($root);
        $paths = array_map(fn (string $path): string => $this->context->environment->containerPath($path), array_values($extensions));
        $line = $this->context->environment->php(array_merge(
            [$this->context->environment->containerPath(DrupalProjectDetector::vendorDir($root) . '/phpstan/phpstan/phpstan'), 'analyse', '-c', $this->context->environment->containerPath($config), '--no-progress', '--error-format=json', '--memory-limit=-1'],
            $paths,
        ));
        $result = $this->context->runner->run($line->command, $root, $line->env, null, 1800);
        @unlink($config);

        $json = json_decode(substr($result->output, (int) strpos($result->output, '{')), true);
        if (!is_array($json) || !isset($json['files'])) {
            return null;
        }
        return new UpgradeStatusReport(self::issues((array) $json['files'], $extensions));
    }

    /**
     * Blocking findings from phpstan's JSON output, attributed to the extension that owns each file.
     *
     * @param array<string, mixed> $files
     * @param array<string, string> $extensions name => absolute directory
     * @return list<array{extension: string, file: string, line: int, severity: string, message: string}>
     */
    public static function issues(array $files, array $extensions): array
    {
        $issues = [];
        foreach ($files as $file => $data) {
            $extension = 'other';
            foreach ($extensions as $name => $directory) {
                if (str_starts_with((string) $file, rtrim($directory, '/') . '/')) {
                    $extension = $name;
                    break;
                }
            }
            foreach ((array) ($data['messages'] ?? []) as $message) {
                $identifier = (string) ($message['identifier'] ?? '');
                if (!in_array($identifier, self::BLOCKING, true) && !str_ends_with($identifier, '.deprecated')) {
                    continue;
                }
                $issues[] = [
                    'extension' => $extension,
                    'file' => (string) $file,
                    'line' => (int) ($message['line'] ?? 0),
                    'severity' => in_array($identifier, self::BLOCKING, true) ? 'error' : 'warning',
                    'message' => trim((string) ($message['message'] ?? '')),
                ];
            }
        }
        return $issues;
    }

    private function writeConfig(string $root): string
    {
        $vendor = DrupalProjectDetector::vendorDir($root);
        $includes = [];
        // Without phpstan/extension-installer the Drupal and deprecation rules must be included by hand.
        if (!is_dir($vendor . '/phpstan/extension-installer')) {
            foreach (['/mglaman/phpstan-drupal/extension.neon', '/mglaman/phpstan-drupal/rules.neon', '/phpstan/phpstan-deprecation-rules/rules.neon'] as $file) {
                if (is_file($vendor . $file)) {
                    $includes[] = '  - ' . $this->context->environment->containerPath($vendor . $file);
                }
            }
        }
        $docroot = $this->context->environment->containerPath(DrupalProjectDetector::docroot($root));
        $neon = ($includes !== [] ? "includes:\n" . implode("\n", $includes) . "\n" : '')
            . "parameters:\n  level: 0\n  reportUnmatchedIgnoredErrors: false\n"
            . "  excludePaths:\n    analyse:\n      - '*/vendor/*'\n      - '*/node_modules/*'\n"
            . "  drupal:\n    drupal_root: '{$docroot}'\n";
        $file = $root . '/.druid-phpstan-' . bin2hex(random_bytes(4)) . '.neon';
        file_put_contents($file, $neon);
        return $file;
    }
}
