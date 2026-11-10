<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Routing;

use Winglet\Core\Http\Request;

/**
 * Router using generated route map (var/routes.php).
 *
 * Matching:
 * 1) static[METHOD][PATH]
 * 2) compiled dynamic[METHOD] regex-loop (compiled once per map load)
 *
 * Caching/Optimization:
 * - Precompiles dynamic route entries into a compact structure.
 * - Optional hot-reload via mtime check with throttle.
 */
final class Router
{
    /** @var array<string,array<string,array{controller:string,action:string,access: array<string,mixed>|null}>> */
    private array $static = [];

    /**
     * @var array<string,list<array{
     *   regex:string,
     *   params:list<string>,
     *   controller:string,
     *   action:string,
     *   access: array<string,mixed>|null
     * }>>
     */
    private array $dynamic = [];

    private string $mapFile;
    private int $mapMtime = 0;

    private bool $reloadOnChange;
    private int $reloadCheckIntervalMs;
    private float $lastReloadCheckAt = 0.0;

    public function __construct(
        string $routeMapPhpFile,
        bool $reloadOnChange = true,
        int $reloadCheckIntervalMs = 0
    ) {
        $this->mapFile = $routeMapPhpFile;
        $this->reloadOnChange = $reloadOnChange;
        $this->reloadCheckIntervalMs = max(0, $reloadCheckIntervalMs);

        $this->loadMap();
    }

    public function match(Request $request): ?RouteMatch
    {
        $this->reloadIfChanged();

        $m = strtoupper($request->method);
        $p = $request->path;

        // Static match
        if (isset($this->static[$m][$p])) {
            $r = $this->static[$m][$p];
            return new RouteMatch(
                controller: $r['controller'],
                action: $r['action'],
                params: [],
                access: $r['access'] ?? null
            );
        }

        // Dynamic match
        $dyn = $this->dynamic[$m] ?? [];
        foreach ($dyn as $r) {
            if (!preg_match($r['regex'], $p, $mats)) continue;

            $params = [];
            foreach ($r['params'] as $name) {
                if (isset($mats[$name])) {
                    $params[$name] = $mats[$name];
                }
            }

            return new RouteMatch(
                controller: $r['controller'],
                action: $r['action'],
                params: $params,
                access: $r['access'] ?? null
            );
        }

        return null;
    }

    private function reloadIfChanged(): void
    {
        if (!$this->reloadOnChange) return;

        if ($this->reloadCheckIntervalMs > 0) {
            $now = microtime(true);
            $elapsedMs = (int)(($now - $this->lastReloadCheckAt) * 1000);
            if ($elapsedMs < $this->reloadCheckIntervalMs) {
                return;
            }
            $this->lastReloadCheckAt = $now;
        }

        $mtime = @filemtime($this->mapFile) ?: 0;
        if ($mtime !== $this->mapMtime) {
            $this->loadMap();
        }
    }

    private function loadMap(): void
    {
        if (!is_file($this->mapFile)) {
            $this->static = [];
            $this->dynamic = [];
            $this->mapMtime = 0;
            return;
        }

        $map = require $this->mapFile;
        if (!is_array($map)) {
            $this->static = [];
            $this->dynamic = [];
            $this->mapMtime = (int)(@filemtime($this->mapFile) ?: 0);
            return;
        }

        $this->mapMtime = (int)(@filemtime($this->mapFile) ?: 0);
        $this->compile($map);
    }

    /** @param array<string,mixed> $map */
    private function compile(array $map): void
    {
        $this->static = [];
        $this->dynamic = [];

        $static = $map['static'] ?? [];
        $dynamic = $map['dynamic'] ?? [];

        if (is_array($static)) {
            foreach ($static as $method => $paths) {
                if (!is_array($paths)) continue;
                foreach ($paths as $path => $entry) {
                    if (!is_string($method) || !is_string($path) || !is_array($entry)) continue;
                    $controller = (string)($entry['controller'] ?? '');
                    $action = (string)($entry['action'] ?? '');
                    if ($controller === '' || $action === '') continue;
                    $this->static[strtoupper($method)][$path] = [
                        'controller' => $controller,
                        'action' => $action,
                        'access' => isset($entry['access']) && is_array($entry['access']) ? $entry['access'] : null,
                    ];
                }
            }
        }

        if (is_array($dynamic)) {
            foreach ($dynamic as $method => $entries) {
                if (!is_array($entries)) continue;

                $method = strtoupper((string)$method);
                foreach ($entries as $e) {
                    if (!is_array($e)) continue;

                    $regex = (string)($e['regex'] ?? '');
                    $params = $e['params'] ?? [];
                    $controller = (string)($e['controller'] ?? '');
                    $action = (string)($e['action'] ?? '');
                    if ($regex === '' || $controller === '' || $action === '') continue;

                    $paramNames = [];
                    if (is_array($params)) {
                        foreach ($params as $p) {
                            if (is_string($p) && $p !== '') $paramNames[] = $p;
                        }
                    }

                    $this->dynamic[$method][] = [
                        'regex' => $regex,
                        'params' => $paramNames,
                        'controller' => $controller,
                        'action' => $action,
                        'access' => isset($e['access']) && is_array($e['access']) ? $e['access'] : null,
                    ];
                }
            }
        }
    }
}