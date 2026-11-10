<?php
declare(strict_types=1);

namespace Winglet\Core\Health;

use InvalidArgumentException;
use Throwable;

final class ReadinessCheckRunner
{
    /** @param list<ReadinessProbeInterface> $probes */
    public function __construct(private array $probes = [])
    {
        $names = [];
        foreach ($this->probes as $probe) {
            $name = $probe->name();
            if (isset($names[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate readiness probe name: %s',
                    $name
                ));
            }
            $names[$name] = true;
        }
    }

    /** @return array{ready:bool,checks:array<string,array{ready:bool,code:?string}>} */
    public function run(): array
    {
        $ready = true;
        $checks = [];

        foreach ($this->probes as $probe) {
            try {
                $result = $probe->check();
            } catch (Throwable) {
                $result = ReadinessProbeResult::notReady('PROBE_FAILED');
            }

            $ready = $ready && $result->ready;
            $checks[$probe->name()] = [
                'ready' => $result->ready,
                'code' => $result->code,
            ];
        }

        return [
            'ready' => $ready,
            'checks' => $checks,
        ];
    }
}
