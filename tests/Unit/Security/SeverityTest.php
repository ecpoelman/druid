<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Security;

use Druid\Security\Advisory;
use Druid\Security\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testFromAdvisoryFieldWins(): void
    {
        self::assertSame(Severity::High, Severity::fromAdvisory('high', 'Moderately critical - XSS'));
        self::assertSame(Severity::Moderate, Severity::fromAdvisory('medium', ''));
    }

    public function testFromDrupalRiskWording(): void
    {
        self::assertSame(Severity::Critical, Severity::fromAdvisory(null, 'Drupal core - Highly critical - RCE'));
        self::assertSame(Severity::High, Severity::fromAdvisory(null, 'Webform - Critical - Access bypass'));
        self::assertSame(Severity::Moderate, Severity::fromAdvisory('', 'Token - Moderately critical - XSS'));
        self::assertSame(Severity::Low, Severity::fromAdvisory(null, 'Foo - Less critical'));
        self::assertSame(Severity::Unknown, Severity::fromAdvisory(null, 'Something'));
    }

    public function testHighest(): void
    {
        self::assertSame(Severity::Critical, Severity::highest([Severity::Low, Severity::Critical, Severity::High]));
        self::assertSame(Severity::None, Severity::highest([]));
    }

    public function testAdvisoryFromComposerAudit(): void
    {
        $advisory = Advisory::fromComposerAudit('drupal/core', [
            'advisoryId' => 'PKSA-1',
            'title' => 'Drupal core - Moderately critical - Cache poisoning - SA-CORE-2026-002',
            'affectedVersions' => '>=10.6.0 <10.6.11',
            'cve' => 'CVE-2026-1',
            'link' => 'https://www.drupal.org/sa-core-2026-002',
            'severity' => null,
        ]);
        self::assertSame(Severity::Moderate, $advisory->severity);
        self::assertTrue($advisory->affects('10.6.9'));
        self::assertFalse($advisory->affects('10.6.11'));
        self::assertEquals($advisory, Advisory::fromArray($advisory->toArray()));
    }
}
