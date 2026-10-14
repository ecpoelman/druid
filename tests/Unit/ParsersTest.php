<?php

declare(strict_types=1);

namespace Druid\Tests\Unit;

use Druid\Composer\ComposerRunner;
use Druid\Major\CheckstyleParser;
use Druid\Site\Discovery\DdevDiscovery;
use Druid\Site\Discovery\HerdDiscovery;
use PHPUnit\Framework\TestCase;

final class ParsersTest extends TestCase
{
    public function testHerdLinksTable(): void
    {
        $output = <<<'OUT'
+------+-----+---------------------+---------------------------+
| Site | SSL | URL                 | Path                      |
+------+-----+---------------------+---------------------------+
| mcv  | X   | https://mcv.test    | /Users/erwin/Sites/mcv    |
| api  |     | http://api.test     | /Users/erwin/Sites/api    |
+------+-----+---------------------+---------------------------+
OUT;
        self::assertSame([
            ['mcv', 'https://mcv.test', '/Users/erwin/Sites/mcv'],
            ['api', 'http://api.test', '/Users/erwin/Sites/api'],
        ], HerdDiscovery::parseLinksTable($output));
    }

    public function testDdevList(): void
    {
        $output = '{"level":"info","msg":"","raw":[{"name":"tcv","approot":"/Users/erwin/Sites/tcv","primary_url":"https://tcv.ddev.site","status":"running"}],"time":"x"}';
        self::assertSame([['name' => 'tcv', 'approot' => '/Users/erwin/Sites/tcv', 'url' => 'https://tcv.ddev.site']], DdevDiscovery::parseList($output));
        self::assertSame([], DdevDiscovery::parseList('ddev: command not found'));
    }

    public function testComposerJsonAfterWarnings(): void
    {
        self::assertSame(['a' => 1], ComposerRunner::decodeJson("Warning: something\n{\"a\":1}"));
        self::assertSame([1, 2], ComposerRunner::decodeJson('[1,2]'));
        self::assertNull(ComposerRunner::decodeJson('no json here'));
    }

    public function testCheckstyle(): void
    {
        $output = <<<'OUT'
 [notice] Processing modules...
<?xml version="1.0" encoding="UTF-8"?>
<checkstyle>
  <file name="web/modules/contrib/delete_all/src/Form/DeleteForm.php">
    <error line="12" severity="error" message="Call to deprecated function drupal_set_message()." source="phpstan"/>
  </file>
  <file name="web/modules/custom/mcv/mcv.module">
    <error line="4" severity="warning" message="Deprecated hook." source="phpstan"/>
    <error line="9" severity="error" message="Another." source="phpstan"/>
  </file>
</checkstyle>
OUT;
        $issues = CheckstyleParser::parse($output);
        self::assertNotNull($issues);
        self::assertCount(3, $issues);
        self::assertSame('delete_all', $issues[0]['extension']);
        self::assertSame('mcv', $issues[1]['extension']);
        self::assertSame(12, $issues[0]['line']);
        self::assertSame([], CheckstyleParser::parse('<?xml version="1.0"?><checkstyle></checkstyle>'));
        self::assertNull(CheckstyleParser::parse('Command upgrade_status:checkstyle is not defined.'));
    }
}
