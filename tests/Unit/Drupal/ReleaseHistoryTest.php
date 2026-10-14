<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Drupal;

use Druid\Drupal\DrupalVersion;
use Druid\Drupal\ReleaseHistory;
use Druid\Plan\MajorPlanner;
use PHPUnit\Framework\TestCase;

final class ReleaseHistoryTest extends TestCase
{
    private static function history(): ReleaseHistory
    {
        $history = ReleaseHistory::fromXml('linkit', (string) file_get_contents(__DIR__ . '/../../Fixtures/release-history-linkit.xml'));
        self::assertNotNull($history);
        return $history;
    }

    public function testParsesReleasesInComposerForm(): void
    {
        $history = self::history();
        self::assertSame('Linkit', $history->title);
        self::assertSame(['7.0.2', '7.0.1', '6.1.8', '6.1.7', '6.0.0'], $history->versions());
        self::assertTrue($history->isBranchSupported('6.1.7'));
        self::assertFalse($history->isBranchSupported('5.0.0'));
        self::assertFalse($history->isUnsupported());
        self::assertTrue($history->release('6.1.8')?->isSecurityUpdate());
    }

    public function testCoreCompatibility(): void
    {
        $history = self::history();
        $both = array_map(static fn ($r) => $r->version, $history->releasesSupportingCore(['10.6.18', '11.2.5']));
        self::assertSame(['7.0.2', '7.0.1'], $both);
        $d11 = array_map(static fn ($r) => $r->version, $history->releasesSupportingCore(['11.2.5']));
        self::assertSame(['7.0.2', '7.0.1'], $d11);
    }

    public function testMajorDecisions(): void
    {
        $history = self::history();
        self::assertSame(['action' => 'bridge', 'version' => '7.0.2'], MajorPlanner::decide($history, '6.1.7', '10.6.18', '11.2.5'));
        self::assertSame(['action' => 'none', 'version' => null], MajorPlanner::decide($history, '7.0.1', '10.6.18', '11.2.5'));
        self::assertSame('lenient', MajorPlanner::decide($history, '6.1.7', '10.6.18', '12.0.0')['action']);
        self::assertSame('companion', MajorPlanner::decide($history, '6.1.7', '9.5.11', '11.2.5')['action']);
    }

    public function testDrupalVersionConversion(): void
    {
        self::assertSame('1.15.0', DrupalVersion::toComposer('8.x-1.15'));
        self::assertSame('6.1.0-beta2', DrupalVersion::toComposer('6.1.0-beta2'));
        self::assertSame('token', DrupalVersion::projectName('drupal/token'));
        self::assertSame('drupal', DrupalVersion::projectName('drupal/core-recommended'));
        self::assertNull(DrupalVersion::projectName('symfony/console'));
    }

    public function testRejectsGarbage(): void
    {
        self::assertNull(ReleaseHistory::fromXml('x', '<error>No release history was found for the requested project (x).</error>'));
        self::assertNull(ReleaseHistory::fromXml('x', 'not xml'));
    }
}
