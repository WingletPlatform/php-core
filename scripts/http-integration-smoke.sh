#!/usr/bin/env bash
set -Eeuo pipefail

IMAGE=${WINGLET_BASE_IMAGE:-akikettu/wingletbase:v0.4.0-RC1}
SCRIPT_VERSION=1.2.0
SIMPLEXML_MODE=
for argument in "$@"; do
    case "$argument" in
        --simplexml-info)
            [[ -z "$SIMPLEXML_MODE" ]] || { printf 'Choose only one SimpleXML mode.\n' >&2; exit 2; }
            SIMPLEXML_MODE=info
            ;;
        --simplexml-strict)
            [[ -z "$SIMPLEXML_MODE" ]] || { printf 'Choose only one SimpleXML mode.\n' >&2; exit 2; }
            SIMPLEXML_MODE=strict
            ;;
        v[0-9]*.[0-9]*.[0-9]*) ;;
        *) printf 'Usage: %s [v<major>.<minor>.<patch>] [--simplexml-info|--simplexml-strict]\n' "$0" >&2; exit 2 ;;
    esac
done
CORE_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
CONTAINER_NAME="winglet-core-http-smoke-$$"
WORK_ROOT=
APP_ROOT=
VENDOR_ROOT=
CORE_VENDOR_PATH=
PORT=

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

if [[ -n "${BITBUCKET_BUILD_NUMBER:-}" || -n "${BITBUCKET_PIPELINE_UUID:-}" || -n "${BITBUCKET_CLONE_DIR:-}" ]]; then
    [[ -n "${BITBUCKET_CLONE_DIR:-}" && -d "$BITBUCKET_CLONE_DIR" && ! -L "$BITBUCKET_CLONE_DIR" ]] ||
        fail 'BITBUCKET_CLONE_DIR must name an existing, non-symlink directory in Bitbucket Pipelines.'
    TEMP_PARENT=$(cd "$BITBUCKET_CLONE_DIR" && pwd -P) ||
        fail 'Could not resolve BITBUCKET_CLONE_DIR.'
    case "$CORE_ROOT" in
        "$TEMP_PARENT"|"$TEMP_PARENT"/*) ;;
        *) fail 'The Core checkout is outside BITBUCKET_CLONE_DIR; refusing Docker bind mounts.' ;;
    esac
else
    [[ -d "${TMPDIR:-/tmp}" ]] || fail 'The local temporary directory does not exist.'
    TEMP_PARENT=$(cd "${TMPDIR:-/tmp}" && pwd -P) ||
        fail 'Could not resolve the local temporary directory.'
fi

WORK_ROOT=$(mktemp -d "$TEMP_PARENT/.winglet-core-http-smoke.XXXXXX") ||
    fail 'Could not create a temporary smoke workspace.'
[[ -d "$WORK_ROOT" && ! -L "$WORK_ROOT" ]] ||
    fail 'The temporary smoke workspace is not a real directory.'
WORK_ROOT=$(cd "$WORK_ROOT" && pwd -P) ||
    fail 'Could not resolve the temporary smoke workspace.'
[[ "$WORK_ROOT" == "$TEMP_PARENT"/.winglet-core-http-smoke.* && "$WORK_ROOT" != "$CORE_ROOT" ]] ||
    fail 'The temporary smoke workspace escaped its expected parent; refusing to continue.'
APP_ROOT="$WORK_ROOT/app"
VENDOR_ROOT="$WORK_ROOT/vendor"

cleanup() {
    local result=$?
    if docker container inspect "$CONTAINER_NAME" >/dev/null 2>&1; then
        if [[ $result -ne 0 ]]; then
            docker logs "$CONTAINER_NAME" >&2 || true
        fi
        docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
    fi
    if [[ -e "$WORK_ROOT" || -L "$WORK_ROOT" ]]; then
        if [[ "$WORK_ROOT" == "$TEMP_PARENT"/.winglet-core-http-smoke.* &&
            "$WORK_ROOT" != "$CORE_ROOT" && -d "$WORK_ROOT" && ! -L "$WORK_ROOT" ]]; then
            rm -rf -- "$WORK_ROOT"
        else
            printf 'Refusing to remove an unexpected smoke workspace: %s\n' "$WORK_ROOT" >&2
            result=1
        fi
    fi
    exit "$result"
}
trap cleanup EXIT INT TERM

for dependency in docker curl awk grep sleep; do
    command -v "$dependency" >/dev/null 2>&1 || fail "Required command not found: $dependency"
done
docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable or current user cannot access it.'
docker version --format 'Docker client={{.Client.Version}} server={{.Server.Version}}'

mkdir -p "$APP_ROOT/src/Controller" "$APP_ROOT/resources/config" "$APP_ROOT/var" \
    "$APP_ROOT/public" "$VENDOR_ROOT" "$WORK_ROOT/http"

cat > "$APP_ROOT/src/Controller/SmokeController.php" <<'PHP'
<?php
declare(strict_types=1);

namespace App\Controller;

use RuntimeException;
use Winglet\Core\Http\Controller\AbstractController;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Http\Routing\Attributes\Route;

final class SmokeController extends AbstractController
{
    #[Route('/smoke/{id}')]
    public function route(string $id): array
    {
        return ['route' => 'matched', 'id' => $id];
    }

    #[Route('/smoke/error')]
    public function error(): never
    {
        throw new RuntimeException('smoke exception');
    }

    #[Route('/smoke/security')]
    public function security(): Response
    {
        return $this->file('safe', 'smoke.txt', 'text/plain');
    }

    #[Route('/smoke/options', ['OPTIONS'])]
    public function options(Request $request): array
    {
        return ['method' => $request->method];
    }
}
PHP

cat > "$APP_ROOT/resources/config/10_smoke.php" <<'PHP'
<?php
declare(strict_types=1);

return [
    'app' => ['name' => 'Core HTTP smoke', 'env' => 'prod'],
    'logging' => ['driver' => 'null'],
    'http' => [
        'access_log' => ['enabled' => false],
        'router' => ['reload_on_change' => false],
        'cors' => [
            'enabled' => getenv('SMOKE_CORS') === '1',
            'allow_origin' => ['https://allowed.example.test'],
            'allow_methods' => ['GET', 'POST', 'OPTIONS'],
            'allow_headers' => ['Authorization', 'Content-Type'],
        ],
    ],
];
PHP

cat > "$APP_ROOT/public/index.php" <<'PHP'
<?php
declare(strict_types=1);

require '/opt/core-vendor/autoload.php';
require '/var/www/App/src/Controller/SmokeController.php';

use Winglet\Core\Kernel\Bootstrap\Bootstrapper;
use Winglet\Core\Kernel\HttpKernel;

$response = (new HttpKernel(new Bootstrapper('/var/www')))->handleGlobals();
$response->send();
PHP

cat > "$APP_ROOT/public/.htaccess" <<'APACHE'
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
APACHE

cat > "$WORK_ROOT/build-routes.php" <<'PHP'
<?php
declare(strict_types=1);

require '/opt/core-vendor/autoload.php';
require '/var/www/App/src/Controller/SmokeController.php';

(new Winglet\Core\Http\Routing\RouteMapBuilder())->build(
    [App\Controller\SmokeController::class],
    '/var/www/var/routes.php'
);
PHP

if [[ -f "$CORE_ROOT/vendor/autoload.php" ]]; then
    CORE_VENDOR_PATH="$CORE_ROOT/vendor"
else
    printf 'Core vendor directory is absent; installing locked runtime dependencies into a temporary directory with %s.\n' "$IMAGE"
    docker run --rm -e COMPOSER_VENDOR_DIR=/opt/core-vendor --entrypoint composer \
        -v "$CORE_ROOT:/var/www/Core:ro" \
        -v "$VENDOR_ROOT:/opt/core-vendor" \
        -w /var/www/Core \
        "$IMAGE" install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts
    CORE_VENDOR_PATH="$VENDOR_ROOT"
fi

start_server() {
    local cors_mode=$1
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
    if ! docker run -d --name "$CONTAINER_NAME" \
        --publish 127.0.0.1::80/tcp \
        -e "SMOKE_CORS=$cors_mode" \
        -v "$APP_ROOT:/var/www/App:ro" \
        -v "$APP_ROOT/public:/var/www/html:ro" \
        -v "$CORE_ROOT:/var/www/Core:ro" \
        -v "$CORE_ROOT/src:/opt/src:ro" \
        -v "$CORE_VENDOR_PATH:/opt/core-vendor:ro" \
        -v "$WORK_ROOT/build-routes.php:/tmp/build-routes.php:ro" \
        "$IMAGE" sh -ec '/usr/local/bin/setup-runtime-dirs && php /tmp/build-routes.php && touch /var/www/var/.routes-ready && exec /usr/local/bin/start-services' >/dev/null; then
        fail "Docker rejected creation or startup of the Base container '$CONTAINER_NAME'; see the Docker error above."
    fi
    docker exec "$CONTAINER_NAME" php -v
    docker image inspect --format 'Base image reference={{.RepoTags}} image_id={{.Id}} repo_digests={{json .RepoDigests}}' "$IMAGE"

    local port_attempt inspection container_state container_exit_code container_state_error host_ip routes_ready
    PORT=
    routes_ready=0
    for port_attempt in {1..200}; do
        if ! inspection=$(docker inspect --format '{{.State.Status}}|{{.State.ExitCode}}|{{.State.Error}}|{{range index .NetworkSettings.Ports "80/tcp"}}{{.HostIp}}|{{.HostPort}}{{end}}' "$CONTAINER_NAME" 2>&1); then
            fail "Could not inspect the started Base container '$CONTAINER_NAME': $inspection"
        fi
        IFS='|' read -r container_state container_exit_code container_state_error host_ip PORT <<< "$inspection"
        if [[ "$container_state" == exited || "$container_state" == dead ]]; then
            fail "Base container '$CONTAINER_NAME' exited during route generation or startup (exit code ${container_exit_code:-unknown}${container_state_error:+; Docker error: $container_state_error})."
        fi
        if docker exec "$CONTAINER_NAME" test -f /var/www/var/.routes-ready >/dev/null 2>&1; then
            routes_ready=1
            break
        fi
        PORT=
        sleep 0.1
    done
    [[ "$routes_ready" -eq 1 ]] || fail "Route generation in Base container '$CONTAINER_NAME' did not complete within 20 seconds."
    [[ "$host_ip" == 127.0.0.1 && "$PORT" =~ ^[0-9]+$ ]] && (( PORT >= 1 && PORT <= 65535 )) || fail "Base container '$CONTAINER_NAME' is running, but Docker did not report a valid 127.0.0.1 host-port mapping for TCP 80."
    local attempt
    for attempt in {1..40}; do
        if curl --silent --show-error --fail "http://127.0.0.1:$PORT/smoke/ready" >/dev/null 2>&1; then
            return
        fi
        sleep 0.5
    done
    fail 'Apache/Core did not become ready within 20 seconds.'
}

request() {
    local name=$1 method=$2 path=$3 expected_status=$4
    shift 4
    local headers="$WORK_ROOT/http/$name.headers"
    local body="$WORK_ROOT/http/$name.body"
    local actual_status
    actual_status=$(curl --silent --show-error -X "$method" -D "$headers" -o "$body" \
        -w '%{http_code}' "$@" "http://127.0.0.1:$PORT$path") || fail "$name request failed"
    [[ "$actual_status" == "$expected_status" ]] || fail "$name expected HTTP $expected_status, got $actual_status"
}

header_is() {
    local name=$1 header=$2 expected=$3
    local actual
    actual=$(awk -v wanted="$header" 'index($0, ":") { key=substr($0,1,index($0,":")-1); if (tolower(key)==tolower(wanted)) { sub(/^[^:]*:[[:space:]]*/, ""); sub(/[\r\n]+$/, ""); print; exit } }' "$WORK_ROOT/http/$name.headers")
    [[ "$actual" == "$expected" ]] || fail "$name header $header expected '$expected', got '${actual:-<absent>}'"
}

header_absent() {
    local name=$1 header=$2
    if awk -v wanted="$header" 'index($0, ":") { key=substr($0,1,index($0,":")-1); if (tolower(key)==tolower(wanted)) found=1 } END {exit !found}' "$WORK_ROOT/http/$name.headers"; then
        fail "$name unexpectedly contains $header"
    fi
}

printf 'HTTP smoke script version: %s\n' "$SCRIPT_VERSION"
printf 'Image: %s\n' "$IMAGE"
printf 'Starting CORS-disabled application...\n'
start_server 0

request route GET /smoke/42 200
grep -Fq '"route":"matched"' "$WORK_ROOT/http/route.body" || fail 'Route response body did not identify matched route.'
grep -Fq '"id":"42"' "$WORK_ROOT/http/route.body" || fail 'Route parameter was not passed to the controller.'
header_absent route Access-Control-Allow-Origin
request error GET /smoke/error 500
grep -Fq '"error":"ServerError"' "$WORK_ROOT/http/error.body" || fail 'Unhandled exception was not converted to the generic 500 response.'
header_absent error Access-Control-Allow-Origin
request security GET /smoke/security 200
header_is security X-Content-Type-Options nosniff
header_is security Server Apache
header_absent security X-Powered-By
request ordinary-options OPTIONS /smoke/options 200
grep -Fq '"method":"OPTIONS"' "$WORK_ROOT/http/ordinary-options.body" || fail 'Ordinary OPTIONS route was not dispatched.'
docker rm -f "$CONTAINER_NAME" >/dev/null

printf 'Starting exact-origin CORS-enabled application...\n'
start_server 1
request cors-allowed GET /smoke/42 200 -H 'Origin: https://allowed.example.test'
header_is cors-allowed Access-Control-Allow-Origin 'https://allowed.example.test'
request cors-denied GET /smoke/42 200 -H 'Origin: https://denied.example.test'
header_absent cors-denied Access-Control-Allow-Origin
request preflight-allowed OPTIONS /smoke/42 204 \
    -H 'Origin: https://allowed.example.test' \
    -H 'Access-Control-Request-Method: POST' \
    -H 'Access-Control-Request-Headers: Authorization, Content-Type'
header_is preflight-allowed Access-Control-Allow-Origin 'https://allowed.example.test'
header_is preflight-allowed Access-Control-Allow-Methods 'GET, POST, OPTIONS'
header_is preflight-allowed Access-Control-Allow-Headers 'Authorization, Content-Type'
request preflight-denied-origin OPTIONS /smoke/42 403 \
    -H 'Origin: https://denied.example.test' \
    -H 'Access-Control-Request-Method: POST'
header_absent preflight-denied-origin Access-Control-Allow-Origin
request preflight-denied-method OPTIONS /smoke/42 403 \
    -H 'Origin: https://allowed.example.test' \
    -H 'Access-Control-Request-Method: DELETE'
header_absent preflight-denied-method Access-Control-Allow-Origin
request preflight-denied-header OPTIONS /smoke/42 403 \
    -H 'Origin: https://allowed.example.test' \
    -H 'Access-Control-Request-Method: POST' \
    -H 'Access-Control-Request-Headers: X-Not-Allowed'
header_absent preflight-denied-header Access-Control-Allow-Origin
request cors-error GET /smoke/error 500 -H 'Origin: https://allowed.example.test'
header_is cors-error Access-Control-Allow-Origin 'https://allowed.example.test'

if [[ -n "$SIMPLEXML_MODE" ]]; then
    # This upstream regression probe runs only when explicitly requested and
    # only in a child CLI process inside the disposable container. It runs
    # after the required HTTP/CORS assertions so an upstream crash cannot mask
    # or prevent those checks.
    cat > "$WORK_ROOT/php24488-regression.php" <<'PHP'
<?php
declare(strict_types=1);

require '/opt/core-vendor/autoload.php';

if (!class_exists(SimpleXMLElement::class)) {
    fwrite(STDERR, "SimpleXML extension is not loaded; the GH-24488 probe cannot be evaluated\n");
    exit(77);
}

for ($i = 0; $i < 2; $i++) {
    $xml = new SimpleXMLElement('<root><child/></root>');
    $holder = new stdClass();
    $holder->xml = $xml;
    $holder->self = $holder;
    try {
        \Winglet\Core\Http\Response::json(['payload' => $holder]);
    } catch (RuntimeException) {
        // Recursive JSON encoding is expected to reject the deliberate cycle.
    }
    unset($xml, $holder);
    gc_collect_cycles();
    $junk[] = str_repeat('x', 100);
}
echo "PASS: Core JSON response cycle cleanup completed\n";
PHP
    docker cp "$WORK_ROOT/php24488-regression.php" "$CONTAINER_NAME:/tmp/php24488-regression.php"
    set +e
    regression_output="$(docker exec "$CONTAINER_NAME" php /tmp/php24488-regression.php 2>&1)"
    regression_status=$?
    set -e
    if [[ "$regression_status" -eq 0 ]]; then
        printf '%s\n' "$regression_output"
        printf 'PASS: GH-24488 isolated regression check completed on %s.\n' "$IMAGE"
    elif [[ "$regression_status" -eq 77 ]]; then
        printf 'SKIP: GH-24488 was not evaluated because SimpleXML is unavailable in %s.\n' "$IMAGE"
        printf '%s\n' "$regression_output"
        if [[ "$SIMPLEXML_MODE" == strict ]]; then
            exit 1
        fi
    elif [[ "$SIMPLEXML_MODE" == info ]]; then
        printf 'KNOWN FAIL: GH-24488 isolated SimpleXML regression check failed on %s (exit %s); informational mode does not block the HTTP smoke.\n' "$IMAGE" "$regression_status"
        printf '%s\n' "$regression_output"
    else
        printf 'FAIL: GH-24488 isolated SimpleXML regression check failed on %s (exit %s).\n' "$IMAGE" "$regression_status" >&2
        printf '%s\n' "$regression_output" >&2
        exit 1
    fi
fi

printf 'PASS: Core HTTP integration smoke completed against %s.\n' "$IMAGE"
