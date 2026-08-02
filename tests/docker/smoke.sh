#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
set -eu

image=${IMAGE_TAG:-secpal-api:test}
postgres_image=${POSTGRES_IMAGE:-postgres:16.10-bookworm@sha256:38471f330eb885e04de130b768d6db4e10469e2311879c7e5c699f6d2d8a1c74}
valkey_image=${VALKEY_IMAGE:-valkey/valkey:9.1.1-trixie@sha256:3acc0687f2a2e1091fae6450d7842dd658c941338cf0a873ddd9e14b9e4ea4dd}
suffix=$$
network="secpal-api-smoke-${suffix}"
postgres="secpal-postgres-${suffix}"
valkey="secpal-valkey-${suffix}"
api="secpal-api-${suffix}"
sqlite_probe="database/container-smoke-${suffix}.sqlite"
if test -e "$sqlite_probe"; then
    echo "Refusing to overwrite existing SQLite smoke probe: ${sqlite_probe}" >&2
    exit 1
fi
tmp_dir=$(mktemp -d)
kek_file="${tmp_dir}/kek"
api_env="${tmp_dir}/api.env"
port_probe="${tmp_dir}/assert-port-closed.php"
cp tests/docker/assert-port-closed.php "$port_probe"
chmod 0444 "$port_probe"
cleanup() {
    docker rm -f "$api" "$valkey" "$postgres" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    rm -f "$sqlite_probe"
    rm -rf "$tmp_dir"
}
trap cleanup EXIT HUP INT TERM
assert_http() {
    path=$1
    expected_status=$2
    expected_fragment=${3:-}
    docker run --rm --network "$network" "$image" php -r '
    $context = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 3, "follow_location" => 0]]);
    $body = @file_get_contents($argv[1], false, $context);
    $statusLine = $http_response_header[0] ?? "";
    preg_match("/\s(\d{3})\s/", $statusLine, $matches);
    $status = isset($matches[1]) ? (int) $matches[1] : 0;
    if ($status !== (int) $argv[2] || ($argv[3] !== "" && !str_contains((string) $body, $argv[3]))) {
        fwrite(STDERR, "Unexpected HTTP response for {$argv[1]}: {$status}\n");
        exit(1);
    }
    ' "http://${api}:8080${path}" "$expected_status" "$expected_fragment"
}
wait_for_http() {
    attempt=0
    while [ "$attempt" -lt 30 ]; do
        if assert_http /health/live 200 '"status":"alive"' >/dev/null 2>&1; then
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    docker logs "$api" >&2
    return 1
}

printf 'container build-context exclusion probe\n' >"$sqlite_probe"
if [ "${SKIP_BUILD:-0}" = 1 ]; then
    docker image inspect "$image" >/dev/null
else
    docker build --tag "$image" .
fi
docker run --rm "$image" php -v | grep -q '^PHP 8\.4\.23'
docker run --rm "$image" frankenphp version | grep -q 'FrankenPHP v1\.12\.6'
docker run --rm "$image" php -m | grep -qx redis
docker run --rm "$image" php --ri redis | grep -q 'Redis Version => 6\.3\.0'
docker run --rm "$image" php -r 'exit(extension_loaded("redis") ? 0 : 1);'
docker run --rm "$image" php -r 'exit(ini_get("upload_max_filesize") === "10M" && ini_get("post_max_size") === "12M" ? 0 : 1);'
docker run --rm "$image" php artisan --version
docker run --rm "$image" php artisan schedule:list
docker run --rm "$image" php artisan queue:work --help >/dev/null
docker run --rm "$image" php artisan schedule:work --help >/dev/null
docker run --rm "$image" ots --version | grep -q 'v0\.7\.2'
docker run --rm "$image" python3 -c 'import opentimestamps'
docker run --rm "$image" frankenphp fmt --diff --config /etc/frankenphp/Caddyfile >/dev/null
docker run --rm "$image" frankenphp validate --config /etc/frankenphp/Caddyfile
test "$(docker image inspect --format '{{index .Config.Healthcheck.Test 0}}' "$image")" = NONE
docker run --rm "$image" sh -eu -c '
    test "$(id -u)" -eq 10001
    test "$(id -g)" -eq 10001
    test -w /app/storage
    test -w /app/bootstrap/cache
    test ! -w /app/artisan
    test ! -e /app/.env
    test ! -e /app/.git
    test ! -d /app/vendor/pestphp
    test ! -d /app/vendor/phpunit
    test -z "$(find /app/database -type f -name "*.sqlite*" -print -quit)"
    ! command -v node >/dev/null
    ! command -v redis-server >/dev/null
    ! command -v valkey-server >/dev/null
    test -z "$(find /app -xdev -perm -0002 -print -quit)"
'
docker image inspect "$image" >"${tmp_dir}/inspect.json"
docker history --no-trunc "$image" >"${tmp_dir}/history.txt"
if grep -Eq 'APP_KEY=|DB_PASSWORD=|REDIS_PASSWORD=|KEK_PATH=' "${tmp_dir}/inspect.json" "${tmp_dir}/history.txt"; then
    echo "Image metadata contains a runtime secret setting" >&2
    exit 1
fi

docker network create "$network" >/dev/null
db_password=$(openssl rand -hex 24)
app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
valkey_password=$(openssl rand -hex 24)
dd if=/dev/urandom of="$kek_file" bs=32 count=1 status=none
chmod 0600 "$kek_file"
docker run --rm --user 0 -v "${kek_file}:/kek" "$image" chown 10001:10001 /kek
{
    printf 'APP_KEY=%s\n' "$app_key"
    printf 'TRUSTED_PROXIES=0.0.0.0/0\nDB_CONNECTION=pgsql\nDB_HOST=%s\nDB_PORT=5432\n' "$postgres"
    printf 'DB_DATABASE=secpal\nDB_USERNAME=secpal\nDB_PASSWORD=%s\n' "$db_password"
    printf 'KEK_PATH=/run/secrets/secpal-kek\n'
} >"$api_env"
chmod 0600 "$api_env"
docker run -d --name "$postgres" --network "$network" \
    -e POSTGRES_DB=secpal -e POSTGRES_USER=secpal -e POSTGRES_PASSWORD="$db_password" \
    "$postgres_image" >/dev/null
attempt=0
until docker exec "$postgres" pg_isready -U secpal -d secpal >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || exit 1
    sleep 1
done
run_api() {
    docker run --rm --network "$network" --env-file "$api_env" \
        -v "${kek_file}:/run/secrets/secpal-kek:ro" "$image" "$@"
}
run_api php artisan migrate --force
run_api php artisan tenant:setup
run_api php artisan tinker --execute='app(\App\Services\RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();'
docker run -d --name "$api" --network "$network" --env-file "$api_env" \
    -v "${kek_file}:/run/secrets/secpal-kek:ro" "$image" >/dev/null
wait_for_http
docker exec "$api" secpal-http-live
assert_http /health/live 200 '"status":"alive"'
assert_http /health/ready 200 '"status":"ready"'
assert_http /v1/not-a-route 404 'Resource not found.'
assert_http /.env 404
assert_http /composer.json 404
assert_http /storage/logs/laravel.log 404
assert_http /.git/config 404
assert_http /.htaccess 404
assert_http /robots.txt.license 404
docker run --rm --network "$network" "$image" php -r '
$context = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 3, "header" => "X-Forwarded-Proto: https\r\n"]]);
@file_get_contents($argv[1], false, $context);
foreach ($http_response_header ?? [] as $header) {
    if (str_starts_with(strtolower($header), "strict-transport-security:")) {
        exit(0);
    }
}
exit(1);
' "http://${api}:8080/health/live"
log_marker="container-log-probe-${suffix}"
email_domain=secpal.app
assert_http "/v1/onboarding/validate-token?token=${log_marker}&email=${log_marker}%40${email_domain}" 422
assert_http "/v1/auth/email/verify/00000000-0000-4000-8000-000000000001/synthetic-hash?expires=2000000000&signature=${log_marker}" 403
docker logs "$api" >"${tmp_dir}/api.log" 2>&1
if grep -q "$log_marker" "${tmp_dir}/api.log" || ! grep -q REDACTED "${tmp_dir}/api.log"; then
    echo "Sensitive query credentials were not redacted from access logs" >&2
    exit 1
fi
for closed_port in 80 443 2019; do
    docker run --rm --network "$network" \
        -v "${port_probe}:/tmp/assert-port-closed.php:ro" \
        "$image" php /tmp/assert-port-closed.php "$api" "$closed_port"
done
docker stop --time 10 "$api" >/dev/null
test "$(docker inspect --format '{{.State.ExitCode}}' "$api")" -eq 0
test "$(docker inspect --format '{{.State.OOMKilled}}' "$api")" = false

docker run -d --name "$valkey" --network "$network" \
    "$valkey_image" valkey-server --requirepass "$valkey_password" --save "" --appendonly no >/dev/null
attempt=0
until docker exec "$valkey" valkey-cli -a "$valkey_password" --no-auth-warning ping >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || exit 1
    sleep 1
done
docker run --rm --network "$network" -e REDIS_HOST="$valkey" -e REDIS_PORT=6379 \
    -e REDIS_PASSWORD="$valkey_password" "$image" php -r '
$client = new Redis();
$client->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"), 3.0);
$client->auth((string) getenv("REDIS_PASSWORD"));
$pong = $client->ping();
if ($pong !== true && $pong !== "+PONG") {
    exit(1);
}
$key = "secpal:container-smoke:".bin2hex(random_bytes(8));
if ($client->setex($key, 30, "ok") !== true || $client->get($key) !== "ok") {
    exit(1);
}
$client->del($key);
'
echo "Container smoke and integration tests passed for ${image}"
