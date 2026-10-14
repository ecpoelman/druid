<?php

declare(strict_types=1);

namespace Druid\Report;

use Druid\Scan\ScanResult;

/**
 * Self-contained HTML report with severity colours (light and dark mode).
 */
final class HtmlRenderer implements ReportRenderer
{
    public function render(array $results): string
    {
        $summaryRows = '';
        $details = '';
        foreach ($results as $scan) {
            $s = $scan->summary();
            $core = self::e($scan->core->current) . ($scan->core->securityTarget !== null ? ' &rarr; <strong>' . self::e($scan->core->securityTarget) . '</strong>' : '');
            $summaryRows .= sprintf(
                '<tr><td><a href="#%s">%s</a></td><td>%s</td><td><span class="badge %s">%d</span></td><td>%d</td><td>%d</td><td>%d</td></tr>',
                self::e($scan->site),
                self::e($scan->site),
                $core,
                $s['security'] === 0 ? 'ok' : self::e($s['severity']->value),
                $s['security'],
                $s['unsupported'],
                $s['minor'],
                $s['major'],
            );
            $details .= $this->siteSection($scan);
        }

        $generated = self::e(date('Y-m-d H:i'));
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Drupal update report</title>
<style>
:root { color-scheme: light dark; --bg:#fff; --fg:#1d1d1f; --muted:#6e6e73; --line:#e5e5ea; }
@media (prefers-color-scheme: dark) { :root { --bg:#1c1c1e; --fg:#f5f5f7; --muted:#98989d; --line:#38383a; } }
body { font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: var(--bg); color: var(--fg); margin: 2rem auto; max-width: 1100px; padding: 0 1rem; }
h1 { margin-bottom: 0; } .muted { color: var(--muted); }
table { width: 100%; border-collapse: collapse; margin: 1rem 0 2rem; }
th, td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid var(--line); vertical-align: top; }
th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
.badge { display: inline-block; min-width: 1.6em; padding: 0 .5em; border-radius: 1em; text-align: center; font-weight: 600; color: #fff; background: #8e8e93; }
.critical { background: #d70015; } .high { background: #ff3b30; } .moderate { background: #ff9500; } .low { background: #0a84ff; }
.ok { background: #34c759; } .unknown { background: #8e8e93; }
.cat-security { color: #ff3b30; font-weight: 600; } .cat-unsupported { color: #ff9500; font-weight: 600; } .cat-minor { color: #0a84ff; }
</style>
</head>
<body>
<h1>Drupal update report</h1>
<p class="muted">Generated {$generated} by druid</p>
<table>
<thead><tr><th>Site</th><th>Core</th><th>Security</th><th>Unsupported</th><th>Minor</th><th>Major</th></tr></thead>
<tbody>{$summaryRows}</tbody>
</table>
{$details}
</body>
</html>

HTML;
    }

    public function extension(): string
    {
        return 'html';
    }

    private function siteSection(ScanResult $scan): string
    {
        $rows = '';
        foreach (ReportRows::build([$scan]) as $row) {
            $severity = $row['severity'] !== '' ? '<span class="badge ' . self::e($row['severity']) . '">' . self::e($row['severity']) . '</span>' : '';
            $rows .= sprintf(
                '<tr><td>%s</td><td class="cat-%s">%s</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td class="muted">%s</td></tr>',
                self::e($row['package']),
                self::e($row['category']),
                self::e($row['category']),
                $severity,
                self::e($row['installed']),
                self::e($row['recommended']),
                self::e($row['advisories']),
                self::e($row['note']),
            );
        }
        $body = $rows === ''
            ? '<p>Everything is up to date.</p>'
            : "<table><thead><tr><th>Package</th><th>Category</th><th>Severity</th><th>Installed</th><th>Recommended</th><th>Advisories</th><th>Note</th></tr></thead><tbody>{$rows}</tbody></table>";

        $warnings = '';
        foreach ($scan->warnings as $warning) {
            $warnings .= '<p class="muted">&#9888; ' . self::e($warning) . '</p>';
        }
        return sprintf('<h2 id="%s">%s</h2><p class="muted">Scanned %s &middot; %s</p>%s%s', self::e($scan->site), self::e($scan->site), self::e($scan->scannedAt), self::e($scan->root), $body, $warnings);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
