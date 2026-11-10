<?php
declare(strict_types=1);
namespace Winglet\Core\Tests\Unit\Http;
use PHPUnit\Framework\TestCase;
use Winglet\Core\Http\Response;
final class ResponseTest extends TestCase
{
    public function testWithHeaderReturnsCopiedResponse(): void
    {
        $original = Response::json(['ok' => true]);
        $copy = $original->withHeader('X-Request-Id', 'abc');
        self::assertNotSame($original, $copy);
        self::assertArrayNotHasKey('X-Request-Id', $original->headers);
        self::assertSame('abc', $copy->headers['X-Request-Id']);
    }
}
