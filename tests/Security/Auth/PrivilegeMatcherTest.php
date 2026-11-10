<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use Winglet\Core\Security\AccessControl\PrivilegeMatcher;
use Winglet\Core\Tests\TestCase;

final class PrivilegeMatcherTest extends TestCase
{
    #[DataProvider('matchingCases')]
    public function testGrantWildcardsAreCaseSensitiveAndDemandsMustBeExact(string $grant, string $required, bool $expected): void
    {
        self::assertSame($expected, PrivilegeMatcher::matches([$grant], $required));
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function matchingCases(): iterable
    {
        yield 'global wildcard grant' => ['*', 'credits.read', true];
        yield 'namespace wildcard grant' => ['credits.*', 'credits.read', true];
        yield 'nested namespace wildcard grant' => ['credits.reports.*', 'credits.reports.export', true];
        yield 'namespace wildcard does not match sibling' => ['credits.*', 'credit.read', false];
        yield 'case sensitive grant' => ['Credits.*', 'credits.read', false];
        yield 'global wildcard demand is invalid' => ['*', '*', false];
        yield 'namespace wildcard demand is invalid' => ['credits.*', 'credits.*', false];
        yield 'embedded wildcard demand is invalid' => ['*', 'credits.*.read', false];
        yield 'malformed wildcard grant is ignored' => ['credits.*.read', 'credits.read', false];
        yield 'invalid exact grant characters are ignored' => ['credits.read:all', 'credits.read:all', false];
        yield 'invalid demand characters are rejected' => ['*', 'credits.read:all', false];
        yield 'empty namespace segment is rejected' => ['credits.*', 'credits..read', false];
        yield 'Unicode letters and numbers are supported' => ['väylä.2.*', 'väylä.2.lue', true];
    }
}
