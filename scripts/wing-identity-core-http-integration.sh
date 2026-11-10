#!/usr/bin/env bash
set -Eeuo pipefail

# Reproducible source-authentic token issuance + Core HTTP consumer check.
# This does not start Identity's DB-backed login/close service.
SCRIPT_VERSION=1.2.0
CORE_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
IDENTITY_ROOT=${WING_IDENTITY_SOURCE:-"$CORE_ROOT/../../archived/wing/identity"}
IDENTITY_REVISION=d821214facf8fd4856e234261ac309a0f390c711
CORE_IMAGE=${WINGLET_BASE_IMAGE:-akikettu/wingletbase:v0.4.0-RC1}
RUN_SUFFIX="$$-$RANDOM-$RANDOM"
IDENTITY_IMAGE="wing-identity-core-http-test:20261022-$RUN_SUFFIX"
CONTAINER_NAME="wing-identity-core-http-$RUN_SUFFIX"
WORK_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/wing-identity-core-http.XXXXXX")
IDENTITY_BUILD_ROOT="$WORK_ROOT/identity-source"
APP_ROOT="$WORK_ROOT/app"
VENDOR_ROOT="$WORK_ROOT/vendor"
CORE_VENDOR_PATH=
PORT=
IMAGE_MAY_EXIST=0
CONTAINER_MAY_EXIST=0
LAST_HTTP_LABEL=

cleanup() {
    local result=$?
    trap - EXIT INT TERM
    set +e
    local container_run_label=
    if [[ $CONTAINER_MAY_EXIST -eq 1 ]]; then
        container_run_label=$(timeout --foreground --kill-after=2s 5s docker inspect --format '{{index .Config.Labels "winglet.core.integration-run"}}' "$CONTAINER_NAME" 2>/dev/null) || container_run_label=
    fi
    if [[ "$container_run_label" == "$RUN_SUFFIX" ]]; then
        if [[ $result -ne 0 ]]; then
            printf '\n--- Core HTTP container logs (last 100 lines) ---\n' >&2
            timeout --foreground --kill-after=3s 15s docker logs --tail 100 "$CONTAINER_NAME" >&2 || true
            printf '\n--- Apache log files (last 50 lines each) ---\n' >&2
            timeout --foreground --kill-after=3s 15s docker exec "$CONTAINER_NAME" sh -c 'for log in /var/log/apache2/error.log /var/log/apache2/access.log; do if test -r "$log"; then echo "--- $log ---"; tail -n 50 "$log"; fi; done' >&2 || true
        fi
        timeout --foreground --kill-after=3s 15s docker rm -f --time 5 "$CONTAINER_NAME" >/dev/null 2>&1 || true
    fi
    local image_run_label=
    if [[ $IMAGE_MAY_EXIST -eq 1 ]]; then
        image_run_label=$(timeout --foreground --kill-after=2s 5s docker image inspect --format '{{index .Config.Labels "winglet.core.integration-run"}}' "$IDENTITY_IMAGE" 2>/dev/null) || image_run_label=
    fi
    if [[ "$image_run_label" == "$RUN_SUFFIX" ]]; then timeout --foreground --kill-after=3s 15s docker image rm "$IDENTITY_IMAGE" >/dev/null 2>&1 || true; fi
    if [[ "$WORK_ROOT" == "${TMPDIR:-/tmp}"/wing-identity-core-http.* && -d "$WORK_ROOT" ]]; then rm -rf -- "$WORK_ROOT"; fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
chmod 700 "$WORK_ROOT"
printf 'Temporary resources: container=%s image=%s workdir=%s\n' "$CONTAINER_NAME" "$IDENTITY_IMAGE" "$WORK_ROOT"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    if [[ -n "$LAST_HTTP_LABEL" ]]; then
        local response_file
        for response_file in "$WORK_ROOT/http/$LAST_HTTP_LABEL.headers" "$WORK_ROOT/http/$LAST_HTTP_LABEL.body"; do
            if [[ -s "$response_file" ]]; then
                printf '\n--- %s ---\n' "${response_file##*/}" >&2
                cat "$response_file" >&2
                printf '\n' >&2
            fi
        done
    fi
    exit 1
}

for dependency in docker curl awk grep sleep git timeout install tar; do
    command -v "$dependency" >/dev/null 2>&1 || fail "Required command not found: $dependency"
done
[[ -d "$IDENTITY_ROOT" ]] || fail "Wing Identity source repository not found at $IDENTITY_ROOT (set WING_IDENTITY_SOURCE)."
git -C "$IDENTITY_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "Wing Identity source path is not inside a Git checkout: $IDENTITY_ROOT."
git -C "$IDENTITY_ROOT" cat-file -e "$IDENTITY_REVISION^{commit}" 2>/dev/null || fail "Wing Identity checkout does not contain pinned commit $IDENTITY_REVISION."
for identity_path in Dockerfile Applications/Identity/Entities/Sessions/Session.php Applications/Identity/Entities/Identities/Identity.php; do
    git -C "$IDENTITY_ROOT" cat-file -e "$IDENTITY_REVISION:$identity_path" 2>/dev/null || fail "Pinned Wing Identity commit $IDENTITY_REVISION is missing expected tracked file: $identity_path."
done
mkdir -p "$IDENTITY_BUILD_ROOT"
if ! git -C "$IDENTITY_ROOT" archive --format=tar "$IDENTITY_REVISION" Dockerfile Applications/Identity | tar -xf - -C "$IDENTITY_BUILD_ROOT"; then
    fail "Could not export the pinned Wing Identity source from commit $IDENTITY_REVISION."
fi
[[ -f "$IDENTITY_BUILD_ROOT/Dockerfile" && -f "$IDENTITY_BUILD_ROOT/Applications/Identity/Entities/Sessions/Session.php" && -f "$IDENTITY_BUILD_ROOT/Applications/Identity/Entities/Identities/Identity.php" ]] || fail 'Pinned Wing Identity export is incomplete; refusing to build an unexpected Docker context.'
timeout --foreground --kill-after=2s 15s docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable or current user cannot access it.'

mkdir -p "$APP_ROOT/src/Controller" "$APP_ROOT/resources/config" "$APP_ROOT/public" "$APP_ROOT/var" "$WORK_ROOT/http" "$VENDOR_ROOT"

cat > "$WORK_ROOT/generate-identity-tokens.php" <<'PHP'
<?php
declare(strict_types=1);

use WingNg\Applications\Identity\Entities\Sessions\Session;
use WingNg\Applications\Identity\Entities\Identities\Identity;

require '/var/www/vendor/autoload.php';

$secret = bin2hex(random_bytes(32));
$secretPath = '/work/identity-test-key';
if (file_put_contents($secretPath, $secret, LOCK_EX) === false || !chmod($secretPath, 0600)) {
    throw new RuntimeException('Could not create the private test-only secret file.');
}
$config = ['environment' => ['Secret' => $secret]];

function issueIdentityToken(int $id, int $level, int $state, DateTime $expires): string
{
    $identity = (new ReflectionClass(Identity::class))->newInstanceWithoutConstructor();
    $identity->setId(42);
    $reflection = new ReflectionClass(Session::class);
    /** @var Session $session */
    $session = $reflection->newInstanceWithoutConstructor();
    foreach ([
        'id' => $id,
        'level' => $level,
        'version' => 1,
        'timeExpire' => $expires,
        'identity' => $identity,
    ] as $property => $value) {
        $field = $reflection->getProperty($property);
        $field->setAccessible(true);
        $field->setValue($session, $value);
    }
    $session->setState($state);
    return $session->getToken();
}

$validExpiry = new DateTime('+10 minutes');
$valid = issueIdentityToken(321, 100, 100, $validExpiry);
$closed = issueIdentityToken(321, 100, 901, $validExpiry);
$expired = issueIdentityToken(324, 100, 100, new DateTime('-1 minute'));
$payload = json_decode(base64_decode(explode('.', $valid)[1], true), true, 512, JSON_THROW_ON_ERROR);
if ($valid !== $closed || ($payload['idi'] ?? null) !== 42 || ($payload['sid'] ?? null) !== 321 || ($payload['slv'] ?? null) !== 100) {
    throw new RuntimeException('Identity Session::getToken() output did not match the expected source contract.');
}
$tokens = [
    'valid' => $valid,
    'closed' => $closed,
    'expired' => $expired,
    'low_level' => issueIdentityToken(322, 10, 100, $validExpiry),
];
$config['environment']['Secret'] = bin2hex(random_bytes(32));
$tokens['secret_mismatch'] = issueIdentityToken(325, 100, 100, $validExpiry);
$config['environment']['Secret'] = $secret;
$parts = explode('.', $valid);
$parts[2] = str_ends_with($parts[2], '0') ? substr($parts[2], 0, -1) . '1' : substr($parts[2], 0, -1) . '0';
$tokens['invalid_signature'] = implode('.', $parts);
file_put_contents('/work/tokens.json', json_encode($tokens, JSON_THROW_ON_ERROR), LOCK_EX);
chmod('/work/tokens.json', 0600);
fwrite(STDOUT, "Issued token cases using Wing Identity Session::getToken().\n");
PHP

cat > "$APP_ROOT/src/Controller/IdentityController.php" <<'PHP'
<?php
declare(strict_types=1);

namespace App\Controller;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\Attributes\Route;
use Winglet\Core\Security\AccessControl\Attributes\Access;

final class IdentityController
{
    #[Route('/identity/level')]
    #[Access(minLevel: 100)]
    public function level(Request $request): array
    {
        $auth = $request->getAttribute('auth');
        $auth = $auth instanceof AuthContext ? $auth : AuthContext::anonymous();
        return ['ok' => true, 'identityId' => $auth->getClientId(), 'sessionId' => $auth->getSessionId(), 'sessionLevel' => $auth->getSessionLevel()];
    }

    #[Route('/identity/role')]
    #[Access(groups: ['identity-admin'])]
    public function role(Request $request): array { return ['ok' => true]; }
}
PHP

cat > "$APP_ROOT/resources/config/10_identity_http.php" <<'PHP'
<?php
declare(strict_types=1);

return [
    'app' => ['name' => 'Wing Identity/Core HTTP integration', 'env' => 'prod'],
    'logging' => ['driver' => 'null'],
    'authentication' => [
        'disable' => false,
        'jwt' => [
            'enabled' => true,
            'mode' => 'legacy',
            'legacy' => ['secret_config_key' => 'environment.Secret'],
        ],
    ],
    'environment' => ['Secret' => file_get_contents('/run/identity-test-key')],
    'http' => [
        'access_log' => ['enabled' => false],
        'router' => ['reload_on_change' => false],
    ],
];
PHP

cat > "$APP_ROOT/public/index.php" <<'PHP'
<?php
declare(strict_types=1);

use Winglet\Core\Kernel\Bootstrap\Bootstrapper;
use Winglet\Core\Kernel\HttpKernel;

require '/opt/core-vendor/autoload.php';
require '/var/www/App/src/Controller/IdentityController.php';
(new HttpKernel(new Bootstrapper('/var/www')))->handleGlobals()->send();
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
require '/var/www/App/src/Controller/IdentityController.php';
(new Winglet\Core\Http\Routing\RouteMapBuilder())->build(
    [App\Controller\IdentityController::class], '/var/www/var/routes.php'
);
PHP

printf 'Wing Identity/Core HTTP integration script version: %s\n' "$SCRIPT_VERSION"
printf 'Building isolated Identity token-issuer image from pinned commit %s...\n' "$IDENTITY_REVISION"
IMAGE_MAY_EXIST=1
timeout --foreground --kill-after=10s 900s docker build --pull --label "winglet.core.integration-run=$RUN_SUFFIX" -f "$IDENTITY_BUILD_ROOT/Dockerfile" -t "$IDENTITY_IMAGE" "$IDENTITY_BUILD_ROOT"
timeout --foreground --kill-after=5s 120s docker run --rm --entrypoint php --workdir /var/www \
    -v "$IDENTITY_BUILD_ROOT/Applications/Identity:/var/www/Applications/Identity:ro" \
    -v "$WORK_ROOT/generate-identity-tokens.php:/tmp/generate-identity-tokens.php:ro" \
    -v "$WORK_ROOT:/work" \
    "$IDENTITY_IMAGE" /tmp/generate-identity-tokens.php

if [[ -f "$CORE_ROOT/vendor/autoload.php" ]]; then
    CORE_VENDOR_PATH="$CORE_ROOT/vendor"
else
    printf 'Installing locked Core runtime dependencies in temporary storage...\n'
    timeout --foreground --kill-after=10s 600s docker run --rm -e COMPOSER_VENDOR_DIR=/opt/core-vendor --entrypoint composer -v "$CORE_ROOT:/var/www/Core:ro" -v "$VENDOR_ROOT:/opt/core-vendor" \
        -w /var/www/Core "$CORE_IMAGE" install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts
    CORE_VENDOR_PATH="$VENDOR_ROOT"
fi

CONTAINER_MAY_EXIST=1
timeout --foreground --kill-after=5s 60s docker run -d --name "$CONTAINER_NAME" --label "winglet.core.integration-run=$RUN_SUFFIX" --publish 127.0.0.1::80/tcp \
    --mount "type=bind,src=$WORK_ROOT/identity-test-key,dst=/run/identity-test-key.source,readonly" \
    -v "$APP_ROOT:/var/www/App:ro" -v "$APP_ROOT/public:/var/www/html:ro" \
    -v "$CORE_ROOT:/var/www/Core:ro" -v "$CORE_ROOT/src:/opt/src:ro" \
    -v "$CORE_VENDOR_PATH:/opt/core-vendor:ro" -v "$WORK_ROOT/build-routes.php:/tmp/build-routes.php:ro" \
    "$CORE_IMAGE" sh -ec '/usr/local/bin/setup-runtime-dirs && php /tmp/build-routes.php && touch /var/www/var/.routes-ready && install -o www-data -g www-data -m 0400 /run/identity-test-key.source /run/identity-test-key && exec /usr/local/bin/start-services' >/dev/null

for attempt in {1..200}; do
    inspection=$(timeout --foreground --kill-after=2s 5s docker inspect --format '{{.State.Status}}|{{.State.ExitCode}}|{{range index .NetworkSettings.Ports "80/tcp"}}{{.HostIp}}|{{.HostPort}}{{end}}' "$CONTAINER_NAME") || fail 'Timed out inspecting Core test container during startup.'
    IFS='|' read -r state exit_code host_ip PORT <<< "$inspection"
    [[ "$state" != exited && "$state" != dead ]] || fail "Core test container exited during startup (code $exit_code)."
    if timeout --foreground --kill-after=2s 5s docker exec "$CONTAINER_NAME" test -f /var/www/var/.routes-ready >/dev/null 2>&1; then break; fi
    sleep 0.1
done
[[ "${host_ip:-}" == 127.0.0.1 && "${PORT:-}" =~ ^[0-9]+$ ]] || fail 'Docker did not publish Core HTTP on localhost.'
for attempt in {1..40}; do
    if curl --silent --show-error --connect-timeout 2 --max-time 5 "http://127.0.0.1:$PORT/identity/level" >/dev/null 2>&1; then break; fi
    sleep 0.5
done

request() {
    local label=$1 expected=$2 path=/identity/level
    if [[ $# -gt 2 && "$3" == /* ]]; then
        path=$3
        shift 3
    else
        shift 2
    fi
    local actual
    LAST_HTTP_LABEL=$label
    actual=$(curl --silent --show-error --connect-timeout 2 --max-time 10 -D "$WORK_ROOT/http/$label.headers" -o "$WORK_ROOT/http/$label.body" -w '%{http_code}' "$@" "http://127.0.0.1:$PORT$path") || fail "$label HTTP request failed"
    [[ "$actual" == "$expected" ]] || fail "$label expected HTTP $expected, got $actual"
}
token() {
    timeout --foreground --kill-after=5s 120s docker run --rm --entrypoint php -v "$WORK_ROOT:/work:ro" "$IDENTITY_IMAGE" \
        -r '$d=json_decode(file_get_contents("/work/tokens.json"), true, 512, JSON_THROW_ON_ERROR); echo $d[$argv[1]];' "$1"
}

request missing 401
request malformed 401 /identity/level -H 'Authorization: Bearer not-a-token'
request valid 200 /identity/level -H "Authorization: Bearer $(token valid)"
grep -Fq '"sessionId":321' "$WORK_ROOT/http/valid.body" || fail 'Core did not project Identity session ID through the HTTP response.'
grep -Fq '"identityId":42' "$WORK_ROOT/http/valid.body" || fail 'Core did not project Identity identity ID through the HTTP response.'
request invalid-signature 401 /identity/level -H "Authorization: Bearer $(token invalid_signature)"
request secret-mismatch 401 /identity/level -H "Authorization: Bearer $(token secret_mismatch)"
request expired 401 /identity/level -H "Authorization: Bearer $(token expired)"
request level-denied 403 /identity/level -H "Authorization: Bearer $(token low_level)"
request session-closed 200 /identity/level -H "Authorization: Bearer $(token closed)"
LAST_HTTP_LABEL=role-denied
actual=$(curl --silent --show-error --connect-timeout 2 --max-time 10 -D "$WORK_ROOT/http/role-denied.headers" -o "$WORK_ROOT/http/role-denied.body" -w '%{http_code}' \
    -H "Authorization: Bearer $(token valid)" "http://127.0.0.1:$PORT/identity/role")
[[ "$actual" == 403 ]] || fail "role-denied expected HTTP 403, got $actual"

printf 'PASS: Identity Session::getToken() -> Core legacy HTTP integration completed.\n'
printf 'Observed: valid 200; missing/invalid signature/expired 401; low level/role 403; closed-state token 200 until exp.\n'
printf 'LIMITATION: Identity login and database-backed session close were not started; close behavior is expiry-bounded.\n'
