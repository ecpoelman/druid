<?php

declare(strict_types=1);

namespace Druid\Major;

/**
 * Parses checkstyle XML as produced by `drush upgrade_status:checkstyle`.
 */
final class CheckstyleParser
{
    /**
     * @return list<array{extension: string, file: string, line: int, severity: string, message: string}>|null
     *         null when the output contains no checkstyle document
     */
    public static function parse(string $output): ?array
    {
        $start = strpos($output, '<?xml');
        if ($start === false) {
            $start = strpos($output, '<checkstyle');
        }
        $end = strrpos($output, '</checkstyle>');
        if ($start === false) {
            return null;
        }
        $xml = $end === false
            ? substr($output, $start)
            : substr($output, $start, $end - $start + strlen('</checkstyle>'));

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            if (str_contains($xml, '<checkstyle') && (str_contains($xml, '/>') || str_contains($xml, '</checkstyle>'))) {
                return [];
            }
            return null;
        }

        $issues = [];
        foreach ($doc->file ?? [] as $file) {
            $name = (string) $file['name'];
            foreach ($file->error ?? [] as $error) {
                $issues[] = [
                    'extension' => self::extensionFromPath($name),
                    'file' => $name,
                    'line' => (int) $error['line'],
                    'severity' => (string) ($error['severity'] ?: 'error'),
                    'message' => trim((string) $error['message']),
                ];
            }
        }
        return $issues;
    }

    /**
     * Extension machine name from a path such as `web/modules/contrib/token/token.module`.
     */
    public static function extensionFromPath(string $path): string
    {
        if (preg_match('#/(?:modules|themes|profiles)/(?:contrib/|custom/)?([a-z0-9_]+)/#i', '/' . ltrim($path, '/'), $m) === 1) {
            return $m[1];
        }
        return basename(dirname($path)) ?: $path;
    }
}
