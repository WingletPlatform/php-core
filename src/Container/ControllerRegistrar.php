<?php
declare(strict_types=1);

namespace Winglet\Core\Container;

use Psr\Log\LoggerInterface;

/**
 * Scans controller directories and warms them into the container.
 *
 * This is optional "controller-as-service" support:
 * - It validates that controller classes can be autoloaded and instantiated.
 * - It also allows future enhancements like decorating controllers, tagging, etc.
 *
 * By default, this registrar:
 * - scans {APP_ROOT}/App/src/Controller
 * - scans framework internal controllers if desired (path is optional)
 */
final class ControllerRegistrar
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param list<string> $dirs Absolute or app-root-relative directories to scan
     */
    public function registerControllers(ServiceContainer $container, array $dirs): void
    {
        $classes = [];

        foreach ($dirs as $dir) {
            $dir = rtrim($dir, "/\\");
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            foreach ($this->iteratePhpFiles($dir) as $file) {
                $fqcn = $this->extractFqcn($file);
                if ($fqcn) {
                    $classes[$fqcn] = true;
                }
            }
        }

        foreach (array_keys($classes) as $class) {
            try {
                if (!class_exists($class)) {
                    // If autoload can't find it, skip; routing might still work via other mechanisms.
                    $this->logger->debug('Controller warmup skipped (class not found)', [
                        'controller' => $class,
                    ]);
                    continue;
                }

                $ref = new \ReflectionClass($class);

                // ✅ Critical fix: skip abstract / non-instantiable (prevents AbstractController warmup crash)
                if (!$ref->isInstantiable()) {
                    $this->logger->debug('Controller warmup skipped (not instantiable)', [
                        'controller' => $class,
                    ]);
                    continue;
                }

                // Warm up: resolve it once so autowire errors are caught early
                $obj = $container->get($class);

                // Optional: you could validate a base type here, but framework
                // currently doesn't enforce a controller base interface.
                if (!is_object($obj)) {
                    $this->logger->warning('Controller warmup did not return an object', [
                        'controller' => $class,
                    ]);
                }
            } catch (\Throwable $e) {
                // Do not stop boot. Log and continue; routing might not hit this controller.
                $this->logger->error('Controller warmup failed', [
                    'controller' => $class,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * @return \Generator<int,string>
     */
    private function iteratePhpFiles(string $dir): \Generator
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $f */
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            if (strtolower($f->getExtension()) !== 'php') continue;
            yield $f->getPathname();
        }
    }

    /**
     * Best-effort extraction of FQCN using PHP tokenization.
     * Returns null if no namespace/class found.
     */
    private function extractFqcn(string $file): ?string
    {
        $code = @file_get_contents($file);
        if (!is_string($code) || $code === '') {
            return null;
        }

        $tokens = @token_get_all($code);
        if ($tokens === []) {
            return null;
        }

        $ns = '';
        $class = '';

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];

            if (is_array($t) && $t[0] === T_NAMESPACE) {
                $ns = $this->readName($tokens, $i + 1);
                continue;
            }

            // Skip anonymous classes: "new class"
            if (is_array($t) && $t[0] === T_NEW) {
                $j = $this->skipIgnorable($tokens, $i + 1);
                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_CLASS) {
                    // anonymous class -> ignore
                    continue;
                }
            }

            if (is_array($t) && $t[0] === T_CLASS) {
                $j = $this->skipIgnorable($tokens, $i + 1);
                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $class = $tokens[$j][1];
                    break;
                }
            }
        }

        if ($class === '') return null;

        return $ns !== '' ? ($ns . '\\' . $class) : $class;
    }

    /**
     * Reads a namespace-like name: Foo\Bar\Baz
     *
     * @param array<int,mixed> $tokens
     */
    private function readName(array $tokens, int $start): string
    {
        $parts = [];
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $t = $tokens[$i];

            if (is_array($t) && ($t[0] === T_STRING || $t[0] === T_NAME_QUALIFIED || $t[0] === T_NAME_FULLY_QUALIFIED)) {
                $parts[] = $t[1];
                continue;
            }

            if ($t === '\\') {
                $parts[] = '\\';
                continue;
            }

            if ($t === ';' || $t === '{') {
                break;
            }

            // ignore whitespace/comments
        }

        $name = implode('', $parts);
        return trim($name, " \\");
    }

    /**
     * @param array<int,mixed> $tokens
     */
    private function skipIgnorable(array $tokens, int $i): int
    {
        $count = count($tokens);
        while ($i < $count) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
                continue;
            }
            break;
        }
        return $i;
    }
}