<?php
declare(strict_types=1);

namespace Winglet\Core\Security\AccessControl;

use Psr\Container\ContainerInterface;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\RouteMatch;

final class AccessDecisionManager
{
    public function __construct(private ContainerInterface $container) {}

    public function assertAllowed(Request $request, RouteMatch $match): void
    {
        $access = $match->access;
        if (!$access) {
            return;
        }

        $auth = $request->getAttribute('auth');
        if (!$auth instanceof AuthContext) {
            $auth = AuthContext::anonymous();
        }

        $min = $access['min'];
        $max = $access['max'];
        $groups = $access['groups'];
        $checker = $access['checker'];

        $requiresAuth = ($min !== null) || ($max !== null) || ($groups !== []) || is_string($checker);

        if ($requiresAuth && !$auth->isAuthenticated()) {
            throw new HttpException(401, 'Authentication required');
        }

        if ($min !== null) {
            $actualLevel = $auth->getSessionLevel();
            if ($actualLevel < (int)$min) {
                throw new HttpException(403, 'Insufficient session level', $this->buildDeniedContext($request, $auth, [
                    'user_session_level' => $actualLevel,
                    'required_session_level' => (int)$min,
                ]));
            }
        }

        if ($max !== null) {
            $actualLevel = $auth->getSessionLevel();
            if ($actualLevel > (int)$max) {
                throw new HttpException(403, 'Session level too high', $this->buildDeniedContext($request, $auth, [
                    'user_session_level' => $actualLevel,
                    'required_max_session_level' => (int)$max,
                ]));
            }
        }

        if ($groups !== []) {
            $userAccessGroups = $this->accessGroupsForCheck($auth);
            $ok = false;
            foreach ($groups as $g) {
                if (in_array((string)$g, $userAccessGroups, true)) {
                    $ok = true;
                    break;
                }
            }

            if (!$ok) {
                throw new HttpException(403, 'Missing required group', $this->buildDeniedContext($request, $auth, [
                    'user_roles' => $this->normalizeStringList($auth->getGroups()),
                    'required_roles' => array_values(array_map('strval', $groups)),
                ]));
            }
        }

        if (is_string($checker) && $checker !== '') {
            if (!class_exists($checker)) {
                throw new HttpException(500, 'Access checker not found', [
                    'checker' => $checker,
                ]);
            }

            $obj = $this->container->get($checker);
            if (!$obj instanceof AccessCheckerInterface) {
                throw new HttpException(500, 'Invalid access checker', [
                    'checker' => $checker,
                    'expected' => AccessCheckerInterface::class,
                ]);
            }

            try {
                $ok = $obj->check($request, $auth, $match->params);
            } catch (HttpException $e) {
                if ($e->statusCode !== 403) {
                    throw $e;
                }

                throw new HttpException(
                    403,
                    'Access denied',
                    $this->buildDeniedContext($request, $auth, $this->buildCheckerFailureContext($obj, $request, $auth, $match->params, $e->context)),
                    $e,
                    $e->getMessage(),
                );
            }

            if (!$ok) {
                throw new HttpException(403, 'Access denied', $this->buildDeniedContext(
                    $request,
                    $auth,
                    $this->buildCheckerFailureContext($obj, $request, $auth, $match->params)
                ));
            }
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function buildDeniedContext(Request $request, AuthContext $auth, array $extra = []): array
    {
        return array_merge([
            'request_path' => $request->originalPath !== '' ? $request->originalPath : $request->path,
            'identity_id' => $auth->getClientId(),
        ], $extra);
    }

    /**
     * @param array<string,string> $routeParams
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function buildCheckerFailureContext(
        AccessCheckerInterface $checker,
        Request $request,
        AuthContext $auth,
        array $routeParams,
        array $extra = [],
    ): array {
        $context = $extra;

        if ($checker instanceof AccessFailureContextProviderInterface) {
            $context = array_merge($checker->buildFailureContext($request, $auth, $routeParams), $context);
        }

        if (!array_key_exists('user_roles', $context)) {
            $context['user_roles'] = $this->normalizeStringList($auth->getGroups());
        }

        if (!array_key_exists('user_privileges', $context)) {
            $context['user_privileges'] = $this->normalizeStringList($auth->getPrivileges());
        }

        return $context;
    }

    /**
     * Access-group checks are historically configured via the Access attribute's
     * `groups` field, but in practice tokens may expose the same effective values
     * either in `roles` or in `groups` depending on the issuing service/version.
     *
     * @return list<string>
     */
    private function accessGroupsForCheck(AuthContext $auth): array
    {
        return $this->normalizeStringList($auth->getGroups());
    }

    /**
     * @param iterable<mixed> $values
     * @return list<string>
     */
    private function normalizeStringList(iterable $values): array
    {
        $result = [];

        foreach ($values as $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $string = trim((string)$value);
            if ($string === '') {
                continue;
            }

            $result[] = $string;
        }

        return array_values(array_unique($result));
    }
}
