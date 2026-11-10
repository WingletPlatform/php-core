<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Support;

use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testFromDirectoryLoadsAlphabeticallyAndLaterFilesOverrideEarlierOnes(): void
    {
        $dir = sys_get_temp_dir() . '/winglet_config_test_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);

        // 10_*.php loads before 20_*.php
        file_put_contents($dir . '/10_base.php', "<?php return ['db' => ['host' => 'localhost', 'port' => 3306], 'feature' => ['a' => true]];\n");
        file_put_contents($dir . '/20_override.php', "<?php return ['db' => ['host' => 'db.internal'], 'feature' => ['b' => true]];\n");

        $cfg = Config::fromDirectory($dir);

        $this->assertSame('db.internal', $cfg->get('db.host'));
        $this->assertSame(3306, $cfg->get('db.port'));
        $this->assertTrue($cfg->get('feature.a'));
        $this->assertTrue($cfg->get('feature.b'));
        $this->assertFalse($cfg->has('does.not.exist'));

        // cleanup
        @unlink($dir . '/10_base.php');
        @unlink($dir . '/20_override.php');
        @rmdir($dir);
    }

    public function testLoadStackPrecedenceInstanceBeatsAppBeatsFramework(): void
    {
        $root = sys_get_temp_dir() . '/winglet_config_stack_' . bin2hex(random_bytes(4));
        $fw = $root . '/fw';
        $app = $root . '/app';
        $inst = $root . '/inst';
        @mkdir($fw, 0775, true);
        @mkdir($app, 0775, true);
        @mkdir($inst, 0775, true);

        file_put_contents($fw . '/10.php', "<?php return ['x' => 1, 'nested' => ['a' => 1, 'b' => 1]];\n");
        file_put_contents($app . '/10.php', "<?php return ['x' => 2, 'nested' => ['b' => 2]];\n");
        file_put_contents($inst . '/10.php', "<?php return ['x' => 3, 'nested' => ['c' => 3]];\n");

        $cfg = Config::loadStack($fw, $app, $inst);

        $this->assertSame(3, $cfg->get('x'));
        $this->assertSame(1, $cfg->get('nested.a'));
        $this->assertSame(2, $cfg->get('nested.b'));
        $this->assertSame(3, $cfg->get('nested.c'));

        // cleanup
        @unlink($fw . '/10.php');
        @unlink($app . '/10.php');
        @unlink($inst . '/10.php');
        @rmdir($fw);
        @rmdir($app);
        @rmdir($inst);
        @rmdir($root);
    }

    public function testLoadStackMigratesLegacyHttpAliasesInsideEachPrecedenceLayer(): void
    {
        $root = sys_get_temp_dir() . '/winglet_config_http_aliases_' . bin2hex(random_bytes(4));
        $fw = $root . '/fw';
        $app = $root . '/app';
        $inst = $root . '/inst';
        @mkdir($fw, 0775, true);
        @mkdir($app, 0775, true);
        @mkdir($inst, 0775, true);

        file_put_contents($fw . '/20_http.php', "<?php return ['http' => ['access_log' => ['level' => 'basic'], 'session' => ['header' => 'X-Session-Id'], 'trusted_proxies' => ['trust_forwarded_prefix' => true]]];\n");
        file_put_contents($app . '/30_logging.php', "<?php return ['logging' => ['access' => ['level' => 'detail']]];\n");
        file_put_contents($app . '/50_session.php', "<?php return ['session' => ['header' => 'X-Legacy-Session', 'ttl_seconds' => 7200]];\n");
        file_put_contents($inst . '/20_http.php', "<?php return ['http' => ['trust_forwarded_prefix' => false]];\n");

        $cfg = Config::loadStack($fw, $app, $inst);

        $this->assertSame('detail', $cfg->get('http.access_log.level'));
        $this->assertSame('X-Legacy-Session', $cfg->get('http.session.header'));
        $this->assertSame(7200, $cfg->get('http.session.lifetime_seconds'));
        $this->assertFalse($cfg->get('http.trusted_proxies.trust_forwarded_prefix'));

        @unlink($fw . '/20_http.php');
        @unlink($app . '/30_logging.php');
        @unlink($app . '/50_session.php');
        @unlink($inst . '/20_http.php');
        @rmdir($fw);
        @rmdir($app);
        @rmdir($inst);
        @rmdir($root);
    }

    public function testGetFirstReadsPrimaryBeforeAlias(): void
    {
        $dir = sys_get_temp_dir() . '/winglet_config_get_first_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);

        file_put_contents($dir . '/10.php', "<?php return ['http' => ['session' => ['header' => 'X-New']], 'session' => ['header' => 'X-Old']];\n");

        $cfg = Config::fromDirectory($dir);

        $this->assertSame('X-New', $cfg->getFirst(['http.session.header', 'session.header'], 'X-Default'));
        $this->assertSame('fallback', $cfg->getFirst(['missing.primary', 'missing.alias'], 'fallback'));

        @unlink($dir . '/10.php');
        @rmdir($dir);
    }

}
