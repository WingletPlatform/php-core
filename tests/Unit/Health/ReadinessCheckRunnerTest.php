<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Health;

use InvalidArgumentException;
use RuntimeException;
use Winglet\Core\Health\ReadinessCheckRunner;
use Winglet\Core\Health\ReadinessProbeInterface;
use Winglet\Core\Health\ReadinessProbeResult;
use PHPUnit\Framework\TestCase;

final class ReadinessCheckRunnerTest extends TestCase
{
    public function testNoProbesIsReady(): void
    {
        self::assertSame(['ready' => true, 'checks' => []], (new ReadinessCheckRunner())->run());
    }

    public function testCombinesSuccessfulAndFailedProbeResults(): void
    {
        $runner = new ReadinessCheckRunner([
            $this->probe('database.connection', ReadinessProbeResult::ready()),
            $this->probe('database.schema', ReadinessProbeResult::notReady('SCHEMA_OUTDATED')),
        ]);

        self::assertSame([
            'ready' => false,
            'checks' => [
                'database.connection' => ['ready' => true, 'code' => null],
                'database.schema' => ['ready' => false, 'code' => 'SCHEMA_OUTDATED'],
            ],
        ], $runner->run());
    }

    public function testProbeExceptionBecomesSafeFailureCode(): void
    {
        $probe = new class implements ReadinessProbeInterface {
            public function name(): string { return 'external.service'; }
            public function check(): ReadinessProbeResult { throw new RuntimeException('secret DSN'); }
        };

        $result = (new ReadinessCheckRunner([$probe]))->run();
        self::assertFalse($result['ready']);
        self::assertSame('PROBE_FAILED', $result['checks']['external.service']['code']);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testDuplicateProbeNamesAreRejected(): void
    {
        $probeA = $this->probe('database.connection', ReadinessProbeResult::ready());
        $probeB = $this->probe('database.connection', ReadinessProbeResult::ready());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate readiness probe name: database.connection');
        new ReadinessCheckRunner([$probeA, $probeB]);
    }

    private function probe(string $name, ReadinessProbeResult $result): ReadinessProbeInterface
    {
        return new class($name, $result) implements ReadinessProbeInterface {
            public function __construct(private string $probeName, private ReadinessProbeResult $result) {}
            public function name(): string { return $this->probeName; }
            public function check(): ReadinessProbeResult { return $this->result; }
        };
    }
}
