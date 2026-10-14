<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Security;

use Druid\Security\VersionResolver;
use PHPUnit\Framework\TestCase;

final class VersionResolverTest extends TestCase
{
    public function testMinimumSecureVersionDrupalStyleRange(): void
    {
        $affected = ['<10.5.12 || >=10.6.0 <10.6.11 || >=11.2.0 <11.2.14'];
        self::assertSame('10.6.11', VersionResolver::minimumSecureVersion('10.6.9', $affected));
        self::assertNull(VersionResolver::minimumSecureVersion('10.6.11', $affected));
    }

    public function testMinimumSecureVersionPackagistCommaRange(): void
    {
        self::assertSame('2.10.3', VersionResolver::minimumSecureVersion('2.10.1', ['>=1.0,<2.2.30|>=2.3.0,<2.10.3']));
    }

    public function testMinimumSecureVersionChainsThroughOverlappingAdvisories(): void
    {
        self::assertSame('7.12.1', VersionResolver::minimumSecureVersion('7.9.2', ['<7.10.0', '>=7.10.0,<7.12.1']));
    }

    public function testMinimumSecureVersionNullWhenUnfixed(): void
    {
        self::assertNull(VersionResolver::minimumSecureVersion('3.5.1', ['>=3.0.0']));
    }

    public function testPrereleaseSortsBeforeStable(): void
    {
        self::assertTrue(VersionResolver::greaterThan('2.0.0', '2.0.0-beta3'));
        self::assertFalse(VersionResolver::isStable('2.0.0-beta3'));
        self::assertTrue(VersionResolver::isStable('2.0.0'));
    }

    public function testSpaceAfterOperatorAndBareVersions(): void
    {
        self::assertTrue(VersionResolver::isAffected('2.1.4', '< 2.1.5 || 2.2.0'));
        self::assertTrue(VersionResolver::isAffected('2.2.0', '< 2.1.5 || 2.2.0'));
        self::assertFalse(VersionResolver::isAffected('2.2.1', '< 2.1.5 || 2.2.0'));
    }

    public function testReleaseListResolvesInclusiveUpperBound(): void
    {
        $releases = ['0.12.x-dev', 'v0.12.24', 'v0.12.19', 'v0.12.18', 'v0.12.14'];
        $affected = ['<=0.11.22|>=0.12.0,<=0.12.18'];
        self::assertSame('0.12.19', VersionResolver::firstUnaffectedRelease('0.12.14', $affected, $releases));
        self::assertSame('0.12.24', VersionResolver::latestStableRelease('0.12.14', $releases));
        self::assertSame('10.6.18', VersionResolver::latestStableRelease('10.6.11', ['11.4.8', '10.6.18', '10.6.17']));
    }

    public function testReleaseListSkipsExactAffectedVersion(): void
    {
        self::assertSame('2.2.1', VersionResolver::firstUnaffectedRelease('2.2.0', ['< 2.1.5 || 2.2.0'], ['2.2.x-dev', '2.2.1', '2.2.0', '2.1.5']));
    }

    public function testConstraintAllowsCommonDrupalConstraints(): void
    {
        self::assertTrue(VersionResolver::constraintAllows('^6.2', '6.2.12'));
        self::assertTrue(VersionResolver::constraintAllows('^6.2', '6.3.1'));
        self::assertFalse(VersionResolver::constraintAllows('^6.2', '7.0.0'));
        self::assertTrue(VersionResolver::constraintAllows('^2.0@beta', '2.0.11'));
        self::assertTrue(VersionResolver::constraintAllows('~2.0.9', '2.0.11'));
        self::assertFalse(VersionResolver::constraintAllows('~2.0.9', '2.1.0'));
        self::assertTrue(VersionResolver::constraintAllows('~1.5', '1.8.0'));
        self::assertTrue(VersionResolver::constraintAllows('^1.0 || ^2.0', '2.2.1'));
        self::assertTrue(VersionResolver::constraintAllows('3.5.*', '3.5.4'));
        self::assertFalse(VersionResolver::constraintAllows('3.5.1', '3.5.2'));
        self::assertTrue(VersionResolver::constraintAllows('>=1.5 <2.0', '1.8.0'));
        self::assertFalse(VersionResolver::constraintAllows('^0.12', '0.13.0'));
        self::assertFalse(VersionResolver::constraintAllows('dev-main', '1.0.0'));
    }

    public function testLatestStableWithinMajorAndMinor(): void
    {
        $versions = ['11.2.5', '11.2.4', '11.1.9', '11.3.0-rc1', '10.6.18'];
        self::assertSame('11.2.5', VersionResolver::latestStable($versions, 11));
        self::assertSame('11.1.9', VersionResolver::latestStable($versions, 11, 1));
        self::assertSame('10.6.18', VersionResolver::latestStable($versions, 10));
        self::assertNull(VersionResolver::latestStable($versions, 12));
    }

    public function testSameMajorAndMinor(): void
    {
        self::assertTrue(VersionResolver::sameMajor('10.6.1', '10.2.0'));
        self::assertFalse(VersionResolver::sameMajor('10.6.1', '11.0.0'));
        self::assertTrue(VersionResolver::sameMinor('10.6.1', '10.6.9'));
        self::assertFalse(VersionResolver::sameMinor('10.6.1', '10.5.9'));
    }

    public function testSatisfiesToleratesInvalidConstraints(): void
    {
        self::assertFalse(VersionResolver::satisfies('1.0.0', 'not a constraint ###'));
    }
}
