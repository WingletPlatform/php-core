<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Http;

use Winglet\Core\Http\Request;
use Winglet\Core\Tests\TestCase;

final class RequestTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];
    /** @var array<string,mixed> */
    private array $get = [];
    /** @var array<string,mixed> */
    private array $post = [];
    /** @var array<string,mixed> */
    private array $cookie = [];
    /** @var array<string,mixed> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
        $this->cookie = $_COOKIE;
        $this->files = $_FILES;
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
        $_COOKIE = $this->cookie;
        $_FILES = $this->files;
        parent::tearDown();
    }

    public function testConfiguredBasePathHasPrecedenceAndIsStripped(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/events?limit=10';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $request = Request::fromGlobals('/api/v1', true);

        self::assertSame('/events', $request->path);
        self::assertSame('/api/v1/events', $request->originalPath);
        self::assertSame('/api/v1', $request->basePath);
        self::assertSame('config', $request->basePathSource);
    }

    public function testForwardedPrefixIsReadFromServerHeadersWhenTrusted(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/gateway/events';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/gateway';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token';

        $request = Request::fromGlobals('', true);

        self::assertSame('/events', $request->path);
        self::assertSame('/gateway', $request->basePath);
        self::assertSame('forwarded', $request->basePathSource);
        self::assertSame('/gateway', $request->headers['X-Forwarded-Prefix']);
        self::assertSame('Bearer token', $request->headers['Authorization']);
    }

    public function testForwardedPrefixIsIgnoredWhenNotTrusted(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/gateway/events';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/gateway';

        $request = Request::fromGlobals('', false);

        self::assertSame('/gateway/events', $request->path);
        self::assertSame('', $request->basePath);
        self::assertSame('none', $request->basePathSource);
    }

    public function testScriptDirectoryIsUsedAsFallbackBasePath(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/winglet/health';
        $_SERVER['SCRIPT_NAME'] = '/winglet/index.php';

        $request = Request::fromGlobals('', false);

        self::assertSame('/health', $request->path);
        self::assertSame('/winglet', $request->basePath);
        self::assertSame('script', $request->basePathSource);
    }
}
