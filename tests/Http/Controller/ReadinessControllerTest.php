<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Http\Controller;

use PHPUnit\Framework\TestCase;
use Winglet\Core\Health\ReadinessCheckRunner;
use Winglet\Core\Health\ReadinessProbeInterface;
use Winglet\Core\Health\ReadinessProbeResult;
use Winglet\Core\Http\Controller\ReadinessController;

final class ReadinessControllerTest extends TestCase
{
    public function testReturns200WhenReady(): void
    {
        $response = (new ReadinessController(new ReadinessCheckRunner()))->handleGet();
        self::assertSame(200, $response->status);
        self::assertSame(['ready' => true, 'checks' => []], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testReturns503WhenProbeFails(): void
    {
        $probe = new class implements ReadinessProbeInterface {
            public function name(): string { return 'database.connection'; }
            public function check(): ReadinessProbeResult { return ReadinessProbeResult::notReady('DB_UNAVAILABLE'); }
        };
        $response = (new ReadinessController(new ReadinessCheckRunner([$probe])))->handleGet();
        self::assertSame(503, $response->status);
        self::assertSame('DB_UNAVAILABLE', json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['checks']['database.connection']['code']);
    }
}
