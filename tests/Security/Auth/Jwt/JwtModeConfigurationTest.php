<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth\Jwt;

use InvalidArgumentException;
use Winglet\Core\Kernel\Bootstrap\Bootstrapper;
use Winglet\Core\Tests\TestCase;

final class JwtModeConfigurationTest extends TestCase
{
    public function testBootstrapRejectsUnknownJwtModeWithDeterministicConfigurationFailure(): void
    {
        $root = sys_get_temp_dir() . '/winglet_jwt_mode_' . bin2hex(random_bytes(6));
        mkdir($root . '/App/resources/config', 0775, true);
        mkdir($root . '/config', 0775, true);
        file_put_contents(
            $root . '/config/99_jwt.php',
            "<?php\nreturn ['authentication' => ['jwt' => ['mode' => 'unexpected']]];\n",
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Unsupported authentication.jwt.mode: unexpected');
            (new Bootstrapper($root))->boot();
        } finally {
            unlink($root . '/config/99_jwt.php');
            rmdir($root . '/config');
            rmdir($root . '/App/resources/config');
            rmdir($root . '/App/resources');
            rmdir($root . '/App');
            rmdir($root);
        }
    }
}
