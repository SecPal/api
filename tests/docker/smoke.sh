#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

set -eu

image=${IMAGE_TAG:-secpal-api:test}
postgres_image=${POSTGRES_IMAGE:-postgres:16.10-bookworm}
valkey_image=${VALKEY_IMAGE:-valkey/valkey:9.1.1-trixie}
suffix=$$
network="secpal-api-smoke-${suffix}"
postgres="secpal-postgres-${suffix}"
valkey="secpal-valkey-${suffix}"
api="secpal-api-${suffix}"
tmp_dir=$(mktemp -d)
kek_file="${tmp_dir}/kek"

cleanup() {
    docker rm -f "$api" "$valkey" "$postgres" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
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

docker build --tag "$image" .

docker run --rm "$image" php -v | grep -q '^PHP 8\.4\.23'
docker run --rm "$image" frankenphp version | grep -q 'FrankenPHP v1\.12\.6'
docker run --rm "$image" php -m | grep -qx redis
docker run --rm "$image" php --ri redis | grep -q 'Redis Version => 6\.3\.0'
docker run --rm "$image" php -r 'exit(extension_loaded("redis") ? 0 : 1);'
docker run --rm "$image" php artisan --version
docker run --rm "$image" php artisan schedule:list
docker run --rm "$image" php artisan queue:work --help >/dev/null
docker run --rm "$image" php artisan schedule:work --help >/dev/null
docker run --rm "$image" ots --version | grep -q 'v0\.7\.2'
docker run --rm "$image" python3 -c 'import opentimestamps'
docker run --rm "$image" frankenphp fmt --diff --config /etc/frankenphp/Caddyfile >/dev/null
docker run --rm "$image" frankenphp validate --config /etc/frankenphp/Caddyfile

docker run --rm "$image" sh -eu -c '
    test "$(id -u)" -eq 10001
    test -w /app/storage
    test -w /app/bootstrap/cache
    test ! -w /app/artisan
    test ! -e /app/.env
    test ! -e /app/.git
    test ! -d /app/vendor/pestphp
    test ! -d /app/vendor/phpunit
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

docker run -d --name "$postgres" --network "$network" \
    -e POSTGRES_DB=secpal \
    -e POSTGRES_USER=secpal \
    -e POSTGRES_PASSWORD="$db_password" \
    "$postgres_image" >/dev/null

attempt=0
until docker exec "$postgres" pg_isready -U secpal -d secpal >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || exit 1
    sleep 1
done

run_api() {
    docker run --rm --network "$network" \
        -e APP_KEY="$app_key" \
        -e DB_CONNECTION=pgsql \
        -e DB_HOST="$postgres" \
        -e DB_PORT=5432 \
        -e DB_DATABASE=secpal \
        -e DB_USERNAME=secpal \
        -e DB_PASSWORD="$db_password" \
        -e KEK_PATH=/run/secrets/secpal-kek \
        -v "${kek_file}:/run/secrets/secpal-kek:ro" \
        "$image" "$@"
}

run_api php artisan migrate --force
run_api php artisan tenant:setup
run_api php artisan tinker --execute='app(\App\Services\RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();'

docker run -d --name "$api" --network "$network" \
    -e APP_KEY="$app_key" \
    -e DB_CONNECTION=pgsql \
    -e DB_HOST="$postgres" \
    -e DB_PORT=5432 \
    -e DB_DATABASE=secpal \
    -e DB_USERNAME=secpal \
    -e DB_PASSWORD="$db_password" \
    -e KEK_PATH=/run/secrets/secpal-kek \
    -v "${kek_file}:/run/secrets/secpal-kek:ro" \
    "$image" >/dev/null

wait_for_http
docker exec "$api" secpal-http-live
assert_http /health/live 200 '"status":"alive"'
assert_http /health/ready 200 '"status":"ready"'
assert_http /v1/not-a-route 404 'Resource not found.'
assert_http /.env 404
assert_http /composer.json 404
assert_http /storage/logs/laravel.log 404
assert_http /.git/config 404

docker run --rm --network "$network" "$image" php -r '
$context = stream_context_create(["http" => ["timeout" => 1]]);
exit(@file_get_contents($argv[1], false, $context) === false ? 0 : 1);
' "http://${api}:2019/config/"

docker stop --time 10 "$api" >/dev/null
test "$(docker inspect --format '{{.State.ExitCode}}' "$api")" -eq 0
test "$(docker inspect --format '{{.State.OOMKilled}}' "$api")" = false

docker run -d --name "$valkey" --network "$network" \
    "$valkey_image" valkey-server \
    --requirepass "$valkey_password" \
    --save "" \
    --appendonly no >/dev/null

attempt=0
until docker exec "$valkey" valkey-cli -a "$valkey_password" --no-auth-warning ping >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || exit 1
    sleep 1
done

docker run --rm --network "$network" \
    -e REDIS_HOST="$valkey" \
    -e REDIS_PORT=6379 \
    -e REDIS_PASSWORD="$valkey_password" \
    "$image" php -r '
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
