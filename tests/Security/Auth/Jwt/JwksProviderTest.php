<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth\Jwt;

use Winglet\Core\Security\Auth\Jwt\JwksProvider;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\Support\ArrayLogger;
use Winglet\Core\Tests\TestCase;

final class JwksProviderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testDisabledProviderReturnsNoKeysWithoutNetworkAccess(): void
    {
        $logger = new ArrayLogger();
        $provider = new JwksProvider($this->config(['enabled' => false]), $logger);

        self::assertSame([], $provider->getVerificationKeys());
        self::assertSame([], $logger->records);
    }

    public function testRejectsUrlOutsideAllowlist(): void
    {
        $logger = new ArrayLogger();
        $provider = new JwksProvider($this->config([
            'enabled' => true,
            'url' => 'https://identity.example.test/jwks',
            'allowed_urls' => ['https://trusted.example.test/jwks'],
        ]), $logger);

        self::assertSame([], $provider->getVerificationKeys());
        self::assertSame('JWKS URL not in allowlist', $logger->lastRecord()['message']);
    }

    public function testRejectsUrlThatDoesNotMatchIssuerPin(): void
    {
        $logger = new ArrayLogger();
        $url = 'https://identity.example.test/jwks';
        $provider = new JwksProvider($this->config([
            'enabled' => true,
            'url' => $url,
            'allowed_urls' => [$url],
            'issuer_pins' => ['identity-issuer' => 'https://other.example.test/jwks'],
        ], issuer: 'identity-issuer'), $logger);

        self::assertSame([], $provider->getVerificationKeys());
        self::assertSame('JWKS URL does not match issuer pin', $logger->lastRecord()['message']);
    }

    public function testSuccessfulFetchCreatesPrivateAtomicCacheFile(): void
    {
        $directory = $this->tempDirectory();
        $jwksFile = $directory . '/source.json';
        file_put_contents($jwksFile, '{"keys":[]}');
        $this->paths[] = $jwksFile;
        $url = 'file://' . $jwksFile;

        $provider = new JwksProvider($this->config([
            'enabled' => true,
            'url' => $url,
            'allowed_urls' => [$url],
            'cache_dir' => $directory,
            'cache_ttl_seconds' => 300,
        ]), new ArrayLogger());

        self::assertSame([], $provider->getVerificationKeys());

        $cache = $directory . '/winglet_jwks_' . hash('sha256', $url) . '.json';
        $this->paths[] = $cache;
        self::assertFileExists($cache);
        self::assertSame(0600, (int)fileperms($cache) & 0777);
        self::assertSame([], glob($directory . '/.winglet_jwks_*') ?: []);
    }

    public function testFutureDatedCacheIsNotTrustedAsFreshOrStale(): void
    {
        $directory = $this->tempDirectory();
        $source = $directory . '/missing.json';
        $url = 'file://' . $source;
        $cache = $directory . '/winglet_jwks_' . hash('sha256', $url) . '.json';
        file_put_contents($cache, json_encode([
            'fetched_at' => time() + 3600,
            'jwks' => ['keys' => []],
        ]));
        $this->paths[] = $cache;

        $logger = new ArrayLogger();
        $provider = new JwksProvider($this->config([
            'enabled' => true,
            'url' => $url,
            'allowed_urls' => [$url],
            'cache_dir' => $directory,
            'cache_ttl_seconds' => 300,
            'use_stale_on_error' => true,
            'max_stale_seconds' => 3600,
        ]), $logger);

        self::assertSame([], $provider->getVerificationKeys());
        self::assertSame('JWKS fetch failed', $logger->lastRecord()['message']);
    }

    /** @param array<string,mixed> $jwks */
    private function config(array $jwks, string $issuer = ''): Config
    {
        $file = tempnam(sys_get_temp_dir(), 'winglet_jwks_config_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, '<?php return ' . var_export([
            'authentication' => [
                'jwt' => [
                    'standard' => [
                        'issuer' => $issuer,
                        'jwks' => $jwks,
                    ],
                ],
            ],
        ], true) . ';');
        return Config::fromPhpFile($file);
    }

    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/winglet_jwks_test_' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $this->paths[] = $directory;
        return $directory;
    }
}
