<?php

declare(strict_types=1);

namespace Druid\Tests\Unit\Environment;

use Druid\Environment\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX sessions only.');
        }
    }

    public function testCommandsCannotPromptOnTheTerminal(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-c', 'echo question > /dev/tty']);
        self::assertNotSame(0, $result->exitCode);
    }

    public function testExitCodesAndOutputPassThrough(): void
    {
        $runner = new ProcessRunner();
        $result = $runner->run(['sh', '-c', 'echo out; echo err >&2; exit 3']);
        self::assertSame([3, "out\n", "err\n"], [$result->exitCode, $result->output, $result->errorOutput]);
        self::assertSame(127, $runner->run(['druid-test-missing-command'])->exitCode);
    }

    public function testAlreadyAppliedPatchesAreSkippedNotReversed(): void
    {
        $runner = new ProcessRunner();
        if (!$runner->has('patch')) {
            self::markTestSkipped('patch is not installed.');
        }
        $dir = sys_get_temp_dir() . '/druid-patch-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/file.txt', "a\nc\n");
        file_put_contents($dir . '/fix.patch', "--- a/file.txt\n+++ b/file.txt\n@@ -1,2 +1,2 @@\n a\n-b\n+c\n");

        $result = $runner->run(['sh', '-c', 'patch -p1 --no-backup-if-mismatch -d . < fix.patch'], $dir);

        self::assertNotSame(0, $result->exitCode);
        self::assertSame("a\nc\n", file_get_contents($dir . '/file.txt'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
