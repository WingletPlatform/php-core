#!/usr/bin/env bash
set -Eeuo pipefail

# Source-authentic PHP and .NET Identity tokens through one Core dual-mode HTTP app.
# Run only through the separately authorized non-root Docker verification gate.
CORE_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
PHP_IDENTITY_ROOT=${WING_IDENTITY_SOURCE:-"$CORE_ROOT/../../archived/wing/identity"}
NET_IDENTITY_ROOT=${WINGLET_NET_IDENTITY_SOURCE:-"$CORE_ROOT/../../winglet.net/winglet.identity"}
PHP_IDENTITY_IMAGE=akikettu/wingng-identity:2026.2.1
CORE_IMAGE=${WINGLET_BASE_IMAGE:-akikettu/wingletbase:v0.4.0-RC1}
RUN_ID="dual-jwt-$$-$RANDOM-$RANDOM"
WORK_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/winglet-dual-jwt.XXXXXX")
NETWORK="${RUN_ID}-network"
NETWORK_CREATED=0
CORE_CONTAINER=
CORE_CONTAINER_CREATED=0
CORE_VENDOR=
PORT=
PHP_SECRET=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
declare -A TOKENS=()

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

cleanup() {
    local result=$?
    trap - EXIT INT TERM
    set +e
    if [[ $CORE_CONTAINER_CREATED -eq 1 ]]; then
        timeout --foreground --kill-after=3s 15s docker rm -f --time 5 "$CORE_CONTAINER" >/dev/null 2>&1 || true
    fi
    if [[ $NETWORK_CREATED -eq 1 ]]; then
        timeout --foreground --kill-after=3s 15s docker network rm "$NETWORK" >/dev/null 2>&1 || true
    fi
    if [[ "$WORK_ROOT" == "${TMPDIR:-/tmp}"/winglet-dual-jwt.* && -d "$WORK_ROOT" ]]; then
        rm -rf -- "$WORK_ROOT"
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for dependency in docker curl dotnet php git timeout od tr; do
    command -v "$dependency" >/dev/null 2>&1 || fail "Required command not found: $dependency"
done
[[ -d "$PHP_IDENTITY_ROOT" ]] || fail 'PHP Wing Identity source checkout is missing; set WING_IDENTITY_SOURCE.'
[[ -d "$NET_IDENTITY_ROOT" ]] || fail '.NET Winglet Identity source checkout is missing; set WINGLET_NET_IDENTITY_SOURCE.'
[[ -f "$PHP_IDENTITY_ROOT/Applications/Identity/Entities/Sessions/Session.php" ]] || fail 'PHP Identity Session producer source is missing.'
[[ -f "$NET_IDENTITY_ROOT/tests/scripts/IssueAsyncE2E/IssueAsyncE2E.csproj" ]] || fail '.NET Identity IssueAsync E2E producer is missing.'
[[ -f "$CORE_ROOT/vendor/autoload.php" ]] || fail 'Core Composer dependencies are missing from this checkout.'
timeout --foreground --kill-after=2s 15s docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable to the current non-root user.'

# Do not pull or retag the requested producer image. This gate records exactly
# which already-local tag, image ID/digest, OCI labels, and PHP runtime it uses.
IMAGE_INFO=$(timeout --foreground --kill-after=2s 10s docker image inspect \
    --format '{{.Id}}|{{join .RepoDigests ","}}|{{index .Config.Labels "org.opencontainers.image.source"}}|{{index .Config.Labels "org.opencontainers.image.version"}}|{{index .Config.Labels "org.opencontainers.image.revision"}}' \
    "$PHP_IDENTITY_IMAGE" 2>/dev/null) || fail "Required local producer image is absent: $PHP_IDENTITY_IMAGE (no pull attempted)."
IFS='|' read -r PHP_IMAGE_ID PHP_REPO_DIGESTS PHP_LABEL_SOURCE PHP_LABEL_VERSION PHP_LABEL_REVISION <<< "$IMAGE_INFO"
[[ "$PHP_IMAGE_ID" =~ ^sha256:[a-f0-9]{64}$ ]] || fail 'Producer image inspection returned no local sha256 image ID.'
mapfile -t PHP_IMAGE_ENV < <(timeout --foreground --kill-after=2s 10s docker image inspect \
    --format '{{range .Config.Env}}{{println .}}{{end}}' "$PHP_IDENTITY_IMAGE")
PHP_CLEAR_ENV=(--env 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' --env 'APP_ENV=test')
for image_env in "${PHP_IMAGE_ENV[@]}"; do
    env_name=${image_env%%=*}
    [[ -n "$env_name" && "$env_name" != PATH && "$env_name" != APP_ENV ]] || continue
    PHP_CLEAR_ENV+=(--env "$env_name=")
done
PHP_RUNTIME=$(timeout --foreground --kill-after=2s 20s docker run --rm --network none \
    --read-only --cap-drop ALL --security-opt no-new-privileges --pids-limit 64 \
    --memory 256m --cpus 1 "${PHP_CLEAR_ENV[@]}" --entrypoint php "$PHP_IDENTITY_IMAGE" -r 'echo PHP_VERSION, " ", PHP_BINARY;') || fail 'Could not run the PHP binary from the requested local producer image.'
CORE_IMAGE_ID=$(timeout --foreground --kill-after=2s 10s docker image inspect --format '{{.Id}}' "$CORE_IMAGE" 2>/dev/null) || fail "Core runtime image is not already local: $CORE_IMAGE (no pull attempted)."

printf 'PHP producer image: tag=%s id=%s repoDigests=%s\n' "$PHP_IDENTITY_IMAGE" "$PHP_IMAGE_ID" "${PHP_REPO_DIGESTS:-none-recorded}"
printf 'PHP producer labels: source=%s version=%s revision=%s\n' "${PHP_LABEL_SOURCE:-unset}" "${PHP_LABEL_VERSION:-unset}" "${PHP_LABEL_REVISION:-unset}"
printf 'PHP runtime: %s\n' "$PHP_RUNTIME"
printf 'Core runtime: tag=%s id=%s\n' "$CORE_IMAGE" "$CORE_IMAGE_ID"
printf 'PHP Identity source: branch=%s commit=%s\n' "$(git -C "$PHP_IDENTITY_ROOT" branch --show-current)" "$(git -C "$PHP_IDENTITY_ROOT" rev-parse HEAD)"
printf 'PHP Identity source changes: %s\n' "$(if [[ -z $(git -C "$PHP_IDENTITY_ROOT" status --porcelain) ]]; then printf clean; else printf 'local changes present'; fi)"
printf '.NET Identity source: branch=%s commit=%s\n' "$(git -C "$NET_IDENTITY_ROOT" branch --show-current)" "$(git -C "$NET_IDENTITY_ROOT" rev-parse HEAD)"
printf '.NET Identity source changes: %s\n' "$(if [[ -z $(git -C "$NET_IDENTITY_ROOT" status --porcelain) ]]; then printf clean; else printf 'local changes present'; fi)"

chmod 700 "$WORK_ROOT"
mkdir -p "$WORK_ROOT/app/src/Controller" "$WORK_ROOT/app/resources/config" \
    "$WORK_ROOT/app/public" "$WORK_ROOT/app/var" "$WORK_ROOT/http" "$WORK_ROOT/dotnet-home" "$WORK_ROOT/nuget"

# The mounted PHP source is read-only. Session::getToken() runs inside the
# exact pre-pulled identity image, with a fresh test-only HMAC key and no DB.
cat > "$WORK_ROOT/issue-php.php" <<'PHP'
<?php
declare(strict_types=1);
namespace WingNg\Lib\Abstracts {
    abstract class AbstractEntity { public function __construct() {} }
}
namespace {
    use WingNg\Applications\Identity\Entities\Sessions\Session as IdentitySession;
    use WingNg\Lib\DataProvider;

    require '/var/www/vendor/autoload.php';
    require '/identity/Session.php';
    $secret = getenv('WINGLET_DUAL_JWT_SECRET');
    if (!is_string($secret) || $secret === '') {
        throw new RuntimeException('Test-only PHP Identity signing key is unavailable.');
    }
    DataProvider::setConfig(['environment' => ['Secret' => $secret]]);
    $issue = static function (int $id, int $level, string $expiration): string {
        $session = new IdentitySession();
        $session->setVersion(1);
        $session->setLevel($level);
        $session->setIdentity(null);
        $session->setTimeExpire(new DateTime($expiration));
        (new ReflectionProperty(IdentitySession::class, 'id'))->setValue($session, $id);
        return $session->getToken();
    };
    $tokens = [
        'legacy' => $issue(4201, 100, '+10 minutes'),
        'legacy_low_level' => $issue(4202, 10, '+10 minutes'),
        'legacy_anonymous' => $issue(4203, 0, '+10 minutes'),
        'legacy_expired' => $issue(4204, 100, '-10 minutes'),
    ];
    $parts = explode('.', $tokens['legacy']);
    $parts[2] = ($parts[2][0] === '0' ? '1' : '0') . substr($parts[2], 1);
    $tokens['legacy_bad_signature'] = implode('.', $parts);
    fwrite(STDOUT, json_encode($tokens, JSON_THROW_ON_ERROR));
}
PHP
umask 077
chmod 644 "$WORK_ROOT/issue-php.php"
timeout --foreground --kill-after=5s 120s docker run --rm --network none --read-only \
    --tmpfs /tmp:rw,noexec,nosuid,size=16m --cap-drop ALL --security-opt no-new-privileges \
    --pids-limit 64 --memory 256m --cpus 1 \
    "${PHP_CLEAR_ENV[@]}" \
    --mount "type=bind,source=$PHP_IDENTITY_ROOT/Applications/Identity/Entities/Sessions/Session.php,target=/identity/Session.php,readonly" \
    --mount "type=bind,source=$WORK_ROOT/issue-php.php,target=/issue-php.php,readonly" \
    --entrypoint php "$PHP_IDENTITY_IMAGE" -r \
    'if (!is_file("/issue-php.php") || !is_readable("/issue-php.php")) { fwrite(STDERR, "PHP producer script mount is inaccessible.\\n"); exit(73); }' \
    >/dev/null || fail 'PHP producer fixture mount check failed before PHP Identity invocation.'
timeout --foreground --kill-after=5s 120s docker run --rm --network none --read-only \
    --tmpfs /tmp:rw,noexec,nosuid,size=16m --cap-drop ALL --security-opt no-new-privileges \
    --pids-limit 64 --memory 256m --cpus 1 --env "WINGLET_DUAL_JWT_SECRET=$PHP_SECRET" \
    "${PHP_CLEAR_ENV[@]}" \
    --mount "type=bind,source=$PHP_IDENTITY_ROOT/Applications/Identity/Entities/Sessions/Session.php,target=/identity/Session.php,readonly" \
    --mount "type=bind,source=$WORK_ROOT/issue-php.php,target=/issue-php.php,readonly" \
    --entrypoint php "$PHP_IDENTITY_IMAGE" /issue-php.php > "$WORK_ROOT/tokens-php.json" \
    || fail 'Real PHP Identity producer invocation failed.'
chmod 644 "$WORK_ROOT/tokens-php.json"
[[ -s "$WORK_ROOT/tokens-php.json" ]] || fail 'PHP Identity producer did not write token fixtures.'

# .NET's existing E2E invokes IdentityService.IssueAsync and ServicePrincipalService.IssueAsync
# against a Testcontainers PostgreSQL instance; its database is ephemeral and fixture-only.
env -i PATH="$PATH" HOME="$WORK_ROOT/dotnet-home" TMPDIR="$WORK_ROOT" \
    DOTNET_CLI_HOME="$WORK_ROOT/dotnet-home" NUGET_PACKAGES="$WORK_ROOT/nuget" \
    DOTNET_NOLOGO=1 DOTNET_CLI_TELEMETRY_OPTOUT=1 \
    timeout --foreground --kill-after=10s 900s dotnet run \
        --project "$NET_IDENTITY_ROOT/tests/scripts/IssueAsyncE2E/IssueAsyncE2E.csproj" \
        --configuration Release -- "$WORK_ROOT" >/dev/null || fail '.NET Identity IssueAsync producer invocation failed.'
[[ -s "$WORK_ROOT/tokens.json" && -s "$WORK_ROOT/public-key.pem" ]] || fail '.NET Identity producer did not write expected fixtures.'
chmod 600 "$WORK_ROOT/tokens.json" "$WORK_ROOT/private-key.pem" "$WORK_ROOT/jwks.json"
chmod 644 "$WORK_ROOT/public-key.pem"

cat > "$WORK_ROOT/app/src/Controller/ProtectedController.php" <<'PHP'
<?php
declare(strict_types=1);
namespace App\Controller;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\Attributes\Route;
final class ProtectedController
{
    #[Route('/protected')]
    public function protected(Request $request): array
    {
        $auth = $request->getAttribute('auth');
        if (!$auth instanceof AuthContext || !$auth->isAuthenticated()) throw new HttpException(401, 'Authentication required');
        if ($auth->getTokenProfile() === 'legacy') {
            if (($auth->getSessionLevel() ?? 0) < 100) throw new HttpException(403, 'Insufficient legacy session level');
            return ['ok' => true, 'profile' => 'legacy', 'session_level' => $auth->getSessionLevel()];
        }
        if (!$auth->hasPrivilege('credits.read')) throw new HttpException(403, 'Required privilege is missing');
        return ['ok' => true, 'profile' => 'canonical', 'subject' => $auth->getActorSubject() ?? $auth->getCallerServiceSubject(), 'privilege' => 'credits.read'];
    }
}
PHP
cat > "$WORK_ROOT/app/public/index.php" <<'PHP'
<?php
declare(strict_types=1);
use Winglet\Core\Kernel\Bootstrap\Bootstrapper;
use Winglet\Core\Kernel\HttpKernel;
require '/opt/core-vendor/autoload.php';
require '/var/www/App/src/Controller/ProtectedController.php';
(new HttpKernel(new Bootstrapper('/var/www')))->handleGlobals()->send();
PHP
cat > "$WORK_ROOT/build-routes.php" <<'PHP'
<?php
declare(strict_types=1);
require '/opt/core-vendor/autoload.php';
require '/var/www/App/src/Controller/ProtectedController.php';
(new Winglet\Core\Http\Routing\RouteMapBuilder())->build([App\Controller\ProtectedController::class], '/var/www/var/routes.php');
PHP
cat > "$WORK_ROOT/app/public/.htaccess" <<'APACHE'
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
APACHE
chmod 755 "$WORK_ROOT/app" "$WORK_ROOT/app/src" "$WORK_ROOT/app/src/Controller" \
    "$WORK_ROOT/app/resources" "$WORK_ROOT/app/resources/config" "$WORK_ROOT/app/public"
chmod 644 "$WORK_ROOT/app/src/Controller/ProtectedController.php" \
    "$WORK_ROOT/app/public/index.php" "$WORK_ROOT/app/public/.htaccess" "$WORK_ROOT/build-routes.php"

if [[ -d "$CORE_ROOT/vendor" ]]; then CORE_VENDOR="$CORE_ROOT/vendor"; else fail 'Core vendor directory is unavailable.'; fi
timeout --foreground --kill-after=3s 15s docker network create --label "winglet.core.dual-jwt-run=$RUN_ID" "$NETWORK" >/dev/null
NETWORK_CREATED=1

start_core() {
    local mode=$1
    local config="$WORK_ROOT/config-$mode"
    mkdir -p "$config"
    cat > "$config/10_dual_jwt_http.php" <<PHP
<?php
declare(strict_types=1);
return [
 'app' => ['name' => 'Core dual JWT real producer HTTP acceptance', 'env' => 'prod'],
 'logging' => ['driver' => 'null'],
 'authentication' => ['disable' => false, 'jwt' => [
  'enabled' => true, 'mode' => '$mode',
  'legacy' => ['secret_config_key' => 'environment.Secret'],
  'standard' => ['issuer' => 'https://identity.e2e.winglet.test', 'keys' => [
   'identity-e2e-key' => ['alg' => 'RS256', 'public_key_pem' => file_get_contents('/run/public-key.pem')],
  ], 'jwks' => ['enabled' => false]],
 ]],
 'environment' => ['Secret' => file_get_contents('/run/legacy-secret')],
 'http' => ['access_log' => ['enabled' => false], 'router' => ['reload_on_change' => false]],
];
PHP
    chmod 755 "$config"
    chmod 644 "$config/10_dual_jwt_http.php"
    CORE_CONTAINER="${RUN_ID}-${mode}"
    CORE_CONTAINER_CREATED=1
    timeout --foreground --kill-after=5s 60s docker run -d --name "$CORE_CONTAINER" --network "$NETWORK" \
        --label "winglet.core.dual-jwt-run=$RUN_ID" --publish 127.0.0.1::80/tcp \
        -v "$WORK_ROOT/app:/var/www/App:ro" -v "$WORK_ROOT/app/public:/var/www/html:ro" \
        -v "$CORE_ROOT:/var/www/Core:ro" \
        -v "$CORE_ROOT/src:/opt/src:ro" -v "$CORE_VENDOR:/opt/core-vendor:ro" \
        -v "$WORK_ROOT/build-routes.php:/tmp/build-routes.php:ro" -v "$config:/var/www/config:ro" \
        -v "$WORK_ROOT/public-key.pem:/run/public-key-source.pem:ro" \
        --env "WINGLET_DUAL_JWT_SECRET=$PHP_SECRET" "$CORE_IMAGE" sh -ec \
        '/usr/local/bin/setup-runtime-dirs && php /tmp/build-routes.php && touch /var/www/var/.routes-ready && install -o www-data -g www-data -m 0444 /run/public-key-source.pem /run/public-key.pem && php -r '\''$s=getenv("WINGLET_DUAL_JWT_SECRET"); if (!is_string($s) || $s === "") exit(73); file_put_contents("/run/legacy-secret",$s); chmod("/run/legacy-secret",0444);'\'' && exec /usr/local/bin/start-services' >/dev/null
    local endpoint
    for _ in {1..200}; do
        endpoint=$(timeout --foreground --kill-after=2s 5s docker inspect --format '{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostIp}}|{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}' "$CORE_CONTAINER" 2>/dev/null) || endpoint=
        if [[ "$endpoint" == 127.0.0.1\|* ]]; then break; fi
        sleep 0.1
    done
    IFS='|' read -r host_ip PORT <<< "$endpoint"
    [[ "${host_ip:-}" == 127.0.0.1 && "${PORT:-}" =~ ^[0-9]+$ ]] || fail "Core $mode HTTP port was not bound to localhost."
    local code=
    for _ in {1..40}; do
        code=$(curl --silent --connect-timeout 2 --max-time 5 -o "$WORK_ROOT/http/readiness-$mode.body" -w '%{http_code}' "http://127.0.0.1:$PORT/protected" || true)
        [[ "$code" == 401 ]] && return
        sleep 0.5
    done
    if [[ -s "$WORK_ROOT/http/readiness-$mode.body" ]]; then
        printf 'Readiness response body: %s\n' "$(head -c 512 "$WORK_ROOT/http/readiness-$mode.body" | tr '\n' ' ')" >&2
    fi
    fail "Core $mode protected HTTP endpoint did not become ready (status ${code:-none})."
}

stop_core() {
    if [[ $CORE_CONTAINER_CREATED -eq 1 ]]; then timeout --foreground --kill-after=3s 15s docker rm -f --time 5 "$CORE_CONTAINER" >/dev/null 2>&1 || true; fi
    CORE_CONTAINER_CREATED=0
}

token() {
    local family=$1 name=$2
    local key="$family:$name"
    if [[ -z "${TOKENS[$key]+set}" ]]; then
        if [[ "$family" == php ]]; then
            TOKENS[$key]=$(docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
                --mount "type=bind,source=$WORK_ROOT/tokens-php.json,target=/tokens-php.json,readonly" \
                "${PHP_CLEAR_ENV[@]}" --entrypoint php "$PHP_IDENTITY_IMAGE" \
                -r '$d=json_decode(file_get_contents("/tokens-php.json"),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[1]];' "$name")
        else
            TOKENS[$key]=$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]];' "$WORK_ROOT/tokens.json" "$name")
        fi
        [[ -n "${TOKENS[$key]}" ]] || fail "Producer did not emit requested fixture: $family/$name"
    fi
    printf '%s' "${TOKENS[$key]}"
}

request() {
    local label=$1 expected=$2 family=${3:-} token_name=${4:-} actual
    local args=(-sS --connect-timeout 2 --max-time 10 -o "$WORK_ROOT/http/$label.body" -w '%{http_code}')
    if [[ -n "$family" ]]; then args+=(-H "Authorization: Bearer $(token "$family" "$token_name")"); fi
    actual=$(curl "${args[@]}" "http://127.0.0.1:$PORT/protected") || fail "$label HTTP request failed"
    [[ "$actual" == "$expected" ]] || fail "$label expected HTTP $expected, got $actual"
    printf 'PASS %-27s HTTP %s\n' "$label" "$actual"
}

start_core dual
request dual_missing 401
request dual_php_legacy 200 php legacy
grep -Fq '"profile":"legacy"' "$WORK_ROOT/http/dual_php_legacy.body" || fail 'PHP Identity token did not use the legacy Core validator.'
request dual_php_low_level 403 php legacy_low_level
request dual_php_slv0_anonymous 401 php legacy_anonymous
request dual_php_expired 401 php legacy_expired
request dual_php_bad_signature 401 php legacy_bad_signature
request dual_dotnet_actor 200 net actor
grep -Fq '"privilege":"credits.read"' "$WORK_ROOT/http/dual_dotnet_actor.body" || fail '.NET actor response did not prove credits.read authorization.'
request dual_dotnet_service 200 net service
request dual_dotnet_missing_privilege 403 net denied
request dual_dotnet_expired 401 net expired
request dual_dotnet_wrong_issuer 401 net wrong_issuer
request dual_dotnet_unknown_key_id 401 net wrong_kid
request dual_dotnet_bad_signature 401 net tampered
stop_core

start_core standard
request standard_rejects_php_legacy 401 php legacy
request standard_accepts_dotnet 200 net actor
stop_core

start_core legacy
request legacy_accepts_php 200 php legacy
request legacy_rejects_dotnet 401 net actor
stop_core

printf 'PASS: both real producer profiles traversed the same Core dual-mode protected HTTP endpoint.\n'
printf 'PHP producer: Wing Identity Session::getToken() in local image tag %s (%s).\n' "$PHP_IDENTITY_IMAGE" "$PHP_IMAGE_ID"
printf '.NET producer: IdentityService.IssueAsync and ServicePrincipalService.IssueAsync against disposable PostgreSQL.\n'
printf 'Token output: redacted; values remained under the mode-0700 temporary directory and were removed by cleanup.\n'
