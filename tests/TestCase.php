<?php
declare(strict_types=1);

namespace Winglet\Core\Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use ReflectionClass;
use Winglet\Core\Http\Request;

abstract class TestCase extends PhpUnitTestCase
{
    /**
     * Build a Request instance for unit tests.
     * Request has a private constructor, so we use reflection.
     */
    protected function makeRequest(
        string $method,
        string $path,
        array $headers = [],
        array $query = [],
        array $post = [],
        ?array $json = null,
        string $rawBody = '',
        array $files = [],
        array $server = [],
    ): Request {
        $rc = new ReflectionClass(Request::class);
        $ctor = $rc->getConstructor();
        $this->assertNotNull($ctor);

        /** @var Request $req */
        $req = $rc->newInstanceWithoutConstructor();
        $ctor->invoke(
            $req,
            strtoupper($method),
            $path,
            $path,      // originalPath
            '',         // basePath
            'none',     // basePathSource
            $headers,
            $query,
            $post,
            $json,
            $rawBody,
            [],         // cookies
            $files,
            $server,
            []          // attributes
        );
        return $req;
    }
}