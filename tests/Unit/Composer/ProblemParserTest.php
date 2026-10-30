<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Composer;

use Druid\Composer\ProblemParser;
use PHPUnit\Framework\TestCase;

final class ProblemParserTest extends TestCase
{
    public function testParsesExactCorePinProblem(): void
    {
        $output = <<<'OUT'
Problem 1
- Root composer.json requires drupal/core 10.6.9 (exact version match: 10.6.9 or 10.6.9.0), found drupal/core[10.6.9] but these were not loaded, because they are affected by security advisories
OUT;
        $problems = ProblemParser::parse($output);
        self::assertCount(1, $problems);
        self::assertSame('exact_core_pin', $problems[0]->kind);
        self::assertSame('drupal/core', $problems[0]->package);
    }

    public function testFindsRootRequirementsADependencyNeedsRaised(): void
    {
        $output = <<<'OUT'
  Problem 1
    - Root composer.json requires drupal/recaptcha_v3 ^2.0.5 -> satisfiable by drupal/recaptcha_v3[2.0.5].
    - drupal/recaptcha_v3 2.0.5 requires drupal/captcha ^2.0 -> found drupal/captcha[2.0.0, ..., 2.0.11] but it conflicts with your root composer.json require (^1.17).
OUT;
        self::assertSame(['drupal/captcha' => '^2.0'], ProblemParser::rootConflicts($output));
        self::assertSame([], ProblemParser::rootConflicts('drupal/foo 1.0.0 requires drupal/core ^10 -> found drupal/core[10.6.9] but these were not loaded'));
    }

    public function testParsesLockedContribProblem(): void
    {
        $output = <<<'OUT'
Problem 2
- drupal/admin_toolbar is locked to version 3.5.1 and an update of this package was not requested.
- drupal/admin_toolbar 3.5.1 requires drupal/core ^9.5 || ^10 || ^11 -> found drupal/core[...] but these were not loaded
OUT;
        $problems = ProblemParser::parse($output);
        self::assertCount(1, $problems);
        self::assertSame('locked_package', $problems[0]->kind);
        self::assertSame('drupal/admin_toolbar', $problems[0]->package);
    }

    public function testDistillCollapsesLockedCascade(): void
    {
        $output = <<<'OUT'
Problem 1
- drupal/token is locked to version 1.13.0 and an update of this package was not requested.

Problem 2
- drupal/pathauto is locked to version 1.12.0 and an update of this package was not requested.

Problem 3
- Root composer.json requires drupal/core 10.6.9 (exact version match), found drupal/core[10.6.9]
OUT;
        $distilled = ProblemParser::distill(ProblemParser::parse($output));
        $kinds = array_map(static fn ($p) => $p->kind, $distilled);
        self::assertContains('exact_core_pin', $kinds);
        self::assertContains('locked_cascade', $kinds);
        self::assertNotContains('locked_package', $kinds);
    }

    public function testBlockersFromProblemsAndWhyNot(): void
    {
        $output = <<<'OUT'
Problem 1
- drupal/webform 6.2.0 requires drupal/core ^10.3 -> found drupal/core[10.2.0]
- drupal/token is locked to version 1.13.0 and an update of this package was not requested.
drupal/recommended-project  dev-main  requires  drupal/core-recommended (10.2.0)
drupal/paragraphs           1.17.0    requires  drupal/core (^10.1)
OUT;
        $blockers = ProblemParser::blockers($output, ['drupal/recommended-project', 'drupal/webform']);
        self::assertSame(['drupal/token', 'drupal/paragraphs'], $blockers);
    }

    public function testAdvisoryIds(): void
    {
        self::assertSame(['PKSA-abcd-1234', 'PKSA-zzzz-9999'], ProblemParser::advisoryIds('PKSA-abcd-1234 and PKSA-zzzz-9999 and PKSA-abcd-1234'));
    }
}
