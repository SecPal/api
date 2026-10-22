#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

image=${IMAGE_TAG:-secpal-api:test}
postgres_image=${POSTGRES_IMAGE:-postgres:18-bookworm@sha256:1c59e2c3c818eaa0f0628f695b36e7c9e362d6b219b36a54a32df645cbd7e1af}
suffix=$$
network="secpal-api-smoke-${suffix}"
postgres="secpal-postgres-${suffix}"
api="secpal-api-${suffix}"
client="secpal-client-${suffix}"
sqlite_probe="database/container-smoke-${suffix}.sqlite"
if test -e "$sqlite_probe"; then
    echo "Refusing to overwrite existing SQLite smoke probe: ${sqlite_probe}" >&2
    exit 1
fi
tmp_dir=$(mktemp -d)
kek_file="${tmp_dir}/kek"
api_env="${tmp_dir}/api.env"
cert_dir="${tmp_dir}/postgres-certs"
port_probe="${tmp_dir}/assert-port-closed.php"
cp tests/docker/assert-port-closed.php "$port_probe"
chmod 0444 "$port_probe"
cleanup() {
    podman rm -f "$client" "$api" "$postgres" >/dev/null 2>&1 || true
    podman network rm "$network" >/dev/null 2>&1 || true
    rm -f "$sqlite_probe"
    rm -rf "$tmp_dir"
}
trap cleanup EXIT HUP INT TERM
assert_http() {
    path=$1
    expected_status=$2
    expected_fragment=${3:-}
    diagnose=${4:-1}
    if podman exec "$client" php -r '
    $context = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 3, "follow_location" => 0]]);
    $body = @file_get_contents($argv[1], false, $context);
    $statusLine = $http_response_header[0] ?? "";
    preg_match("/\s(\d{3})\s/", $statusLine, $matches);
    $status = isset($matches[1]) ? (int) $matches[1] : 0;
    if ($status !== (int) $argv[2] || ($argv[3] !== "" && !str_contains((string) $body, $argv[3]))) {
        fwrite(STDERR, "Unexpected HTTP response for {$argv[1]}: {$status}\n");
        exit(1);
    }
    ' "http://${api}:8080${path}" "$expected_status" "$expected_fragment"; then
        return 0
    fi
    if [ "$diagnose" = 0 ]; then return 1; fi
    # A diagnostic cannot turn the original failed assertion into acceptance.
    # No application bodies, SQL text, credentials or raw runtime logs are emitted.
    podman inspect --format '{{.State.Status}} OOM={{.State.OOMKilled}}' "$api" >&2 || true
    podman exec "$postgres" psql -U secpal -d secpal -Atc \
        "SELECT state, wait_event_type, wait_event, count(*) FROM pg_stat_activity WHERE datname='secpal' GROUP BY 1,2,3" >&2 || true
    podman exec "$client" php -r '
        $started = microtime(true);
        $stream = @fopen($argv[1], "r", false, stream_context_create(["http" => [
            "ignore_errors" => true, "timeout" => 15, "follow_location" => 0,
            "header" => "Connection: close\r\n",
        ]]));
        $status = $http_response_header[0] ?? "NO_RESPONSE";
        fprintf(STDERR, "Diagnostic request: %s; %.3fs; original assertion remains failed\n", $status, microtime(true)-$started);
        if (is_resource($stream)) { fclose($stream); }
    ' "http://${api}:8080${path}" || true
    return 1
}
wait_for_http() {
    attempt=0
    while [ "$attempt" -lt 30 ]; do
        if assert_http /health/live 200 '"status":"alive"' 0 >/dev/null 2>&1; then
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    podman logs "$api" >&2
    return 1
}
assert_output_starts_with() {
    case "$1" in
        "$2"*) ;;
        *) return 1 ;;
    esac
}
assert_output_contains() {
    case "$1" in
        *"$2"*) ;;
        *) return 1 ;;
    esac
}
assert_output_has_line() {
    case "
$1
" in
        *"
$2
"*) ;;
        *) return 1 ;;
    esac
}

printf 'container build-context exclusion probe\n' >"$sqlite_probe"
if [ "${SKIP_BUILD:-0}" = 1 ]; then
    podman image inspect "$image" >/dev/null
else
    buildah build --format oci --file Containerfile --tag "$image" .
fi
if [ -n "${EXPECTED_ARCH:-}" ]; then
    actual_arch=$(uname -m)
    case "$EXPECTED_ARCH:$actual_arch" in
        amd64:x86_64|arm64:aarch64) ;;
        *) echo "Native runner architecture mismatch" >&2; exit 1 ;;
    esac
    image_arch=$(podman image inspect --format '{{.Architecture}}' "$image")
    test "$image_arch" = "$EXPECTED_ARCH"
    podman run --rm -e EXPECTED_ARCH="$EXPECTED_ARCH" "$image" php -r '
        $header = file_get_contents("/usr/local/bin/php", false, null, 0, 20);
        $expected = getenv("EXPECTED_ARCH") === "amd64" ? 62 : 183;
        exit(substr($header, 0, 4) === "\x7fELF" && ord($header[5]) === 1
            && unpack("v", substr($header, 18, 2))[1] === $expected ? 0 : 1);
    '
fi
# Compare actual binaries to the single authoritative, immutable runtime input.
# PHP 8.4.27 is the minimum security patch level for the PHP2Shell repair.
# shellcheck disable=SC2016 # PHP variables must not be expanded by the shell.
expected_runtime=$(php -r '
    $count = preg_match_all(
        "~^ARG FRANKENPHP_IMAGE=docker\\.io/dunglas/frankenphp:([0-9]+\\.[0-9]+\\.[0-9]+)-php(8\\.4\\.[0-9]+)-bookworm@sha256:[a-f0-9]{64}$~m",
        file_get_contents("Containerfile"),
        $matches,
        PREG_SET_ORDER,
    );
    if ($count !== 1 || version_compare($matches[0][2], "8.4.27", "<")) {
        fwrite(STDERR, "Invalid or vulnerable runtime input\n");
        exit(1);
    }
    echo $matches[0][1], " ", $matches[0][2];
')
expected_frankenphp=${expected_runtime%% *}
expected_php=${expected_runtime#* }
php_version=$(podman run --rm "$image" php -v)
assert_output_starts_with "$php_version" "PHP ${expected_php} "
frankenphp_version=$(podman run --rm "$image" frankenphp version)
# shellcheck disable=SC2016 # PHP variables must not be expanded by the shell.
php -r '
    if (preg_match("~\\bFrankenPHP v([0-9]+\\.[0-9]+\\.[0-9]+)\\b~", $argv[1], $matches) !== 1
        || $matches[1] !== $argv[2]) {
        fwrite(STDERR, "Runtime does not match the pinned FrankenPHP input\n");
        exit(1);
    }
' "$frankenphp_version" "$expected_frankenphp"
php_modules=$(podman run --rm "$image" php -m)
if assert_output_has_line "$php_modules" redis; then
    echo "Unsupported PhpRedis extension is installed" >&2
    exit 1
fi
podman run --rm "$image" php -r 'exit(extension_loaded("redis") ? 1 : 0);'
podman run --rm "$image" php -r 'exit(ini_get("upload_max_filesize") === "10M" && ini_get("post_max_size") === "12M" ? 0 : 1);'
php_ini_output=$(podman run --rm "$image" php --ini)
assert_output_contains "$php_ini_output" '/usr/local/etc/php/conf.d/zz-secpal-production.ini'
podman run --rm "$image" sh -eu -c '
    ini=/usr/local/etc/php/conf.d/zz-secpal-production.ini
    test -r "$ini"
    test ! -w "$ini"
    test "$(stat -c "%U:%G %a" "$ini")" = "root:root 644"
'
podman run --rm "$image" sh -eu -c '
    caddyfile=/etc/frankenphp/Caddyfile
    test -r "$caddyfile"
    test ! -w "$caddyfile"
    test "$(stat -c "%U:%G %a" "$caddyfile")" = "root:root 644"
'
podman run --rm "$image" sh -eu -c '
    healthcheck=/usr/local/bin/secpal-http-live
    test -r "$healthcheck"
    test -x "$healthcheck"
    test ! -w "$healthcheck"
    test "$(stat -c "%U:%G %a" "$healthcheck")" = "root:root 755"
'
podman run --rm "$image" sh -eu -c '
    notice=/app/docs/ADDRESS_DATA.md
    test -r "$notice"
    test ! -w "$notice"
    test -r /app/LICENSES/ODbL-1.0.txt
    grep -F "Machine-readable alterations offer" "$notice" >/dev/null
'
run_runtime_cli() {
    podman run --rm \
        -e DB_SSLMODE=verify-full \
        -e DB_SSLROOTCERT=/tmp/production-ca-contract.crt \
        "$image" "$@"
}
run_runtime_cli php artisan --version
run_runtime_cli php artisan schedule:list
run_runtime_cli php artisan queue:work --help >/dev/null
run_runtime_cli php artisan schedule:work --help >/dev/null
podman run --rm "$image" python3 -c 'import opentimestamps'
if git_import=$(podman run --rm "$image" python3 -c 'import git' 2>&1); then exit 1; fi
assert_output_contains "$git_import" 'ModuleNotFoundError'
if podman run --rm "$image" python3 -m pip show GitPython >/dev/null 2>&1; then exit 1; fi
if podman run --rm "$image" python3 -m pip show gitdb >/dev/null 2>&1; then exit 1; fi
if podman run --rm "$image" python3 -m pip show smmap >/dev/null 2>&1; then exit 1; fi
if podman run --rm "$image" python3 -m pip show opentimestamps-client >/dev/null 2>&1; then exit 1; fi
podman run --rm "$image" python3 -m pip check
stamp_usage=$(podman run --rm "$image" python3 /app/scripts/ots-stamp-hash.py 2>&1 || true)
assert_output_contains "$stamp_usage" 'Usage:'
upgrade_usage=$(podman run --rm "$image" python3 /app/scripts/ots-upgrade.py 2>&1 || true)
assert_output_contains "$upgrade_usage" 'EXECUTION_ERROR'
verify_usage=$(podman run --rm "$image" python3 /app/scripts/ots-verify.py 2>&1 || true)
assert_output_contains "$verify_usage" 'Usage:'
podman run --rm "$image" frankenphp fmt --diff --config /etc/frankenphp/Caddyfile >/dev/null
podman run --rm "$image" frankenphp validate --config /etc/frankenphp/Caddyfile
# PHP code, not shell interpolation.
# shellcheck disable=SC2016
podman image inspect "$image" | php -r '
    $image = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR)[0];
    $test = $image["Config"]["Healthcheck"]["Test"] ?? [];
    exit($test === [] || $test === ["NONE"] ? 0 : 1);
'
podman run --rm "$image" sh -eu -c '
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
podman image inspect "$image" >"${tmp_dir}/inspect.json"
podman history --no-trunc "$image" >"${tmp_dir}/history.txt"
if grep -Eq 'APP_KEY=|DB_PASSWORD=|KEK_PATH=' "${tmp_dir}/inspect.json" "${tmp_dir}/history.txt"; then
    echo "Image metadata contains a runtime secret setting" >&2
    exit 1
fi

podman network create "$network" >/dev/null
db_password=$(openssl rand -hex 24)
app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
mkdir -m 0700 "$cert_dir"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -subj '/CN=SecPal container smoke CA' \
    -keyout "${cert_dir}/ca.key" -out "${cert_dir}/ca.crt" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes \
    -subj "/CN=${postgres}" \
    -keyout "${cert_dir}/server.key" -out "${cert_dir}/server.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:%s\nextendedKeyUsage=serverAuth\n' "$postgres" >"${cert_dir}/server.ext"
openssl x509 -req -days 1 -sha256 \
    -in "${cert_dir}/server.csr" \
    -CA "${cert_dir}/ca.crt" -CAkey "${cert_dir}/ca.key" -CAcreateserial \
    -extfile "${cert_dir}/server.ext" -out "${cert_dir}/server.crt" >/dev/null 2>&1
chmod 0755 "$cert_dir"
chmod 0644 "${cert_dir}/ca.crt" "${cert_dir}/server.crt"
chmod 0600 "${cert_dir}/server.key"
podman run --rm --user 0 -v "${cert_dir}:/certs" "$postgres_image" \
    chown postgres:postgres /certs/server.key /certs/server.crt /certs/ca.crt
dd if=/dev/urandom of="$kek_file" bs=32 count=1 status=none
chmod 0600 "$kek_file"
podman run --rm --user 0 -v "${kek_file}:/kek" "$image" chown 10001:10001 /kek
{
    printf 'APP_KEY=%s\n' "$app_key"
    printf 'TRUSTED_PROXIES=0.0.0.0/0\nDB_CONNECTION=pgsql\nDB_HOST=%s\nDB_PORT=5432\n' "$postgres"
    printf 'DB_DATABASE=secpal\nDB_USERNAME=secpal\nDB_PASSWORD=%s\n' "$db_password"
    printf 'DB_SSLMODE=verify-full\nDB_SSLROOTCERT=/run/secrets/postgresql-ca.crt\n'
    printf 'KEK_PATH=/run/secrets/secpal-kek\n'
} >"$api_env"
chmod 0600 "$api_env"
podman run -d --name "$postgres" --network "$network" \
    -e POSTGRES_DB=secpal -e POSTGRES_USER=secpal -e POSTGRES_PASSWORD="$db_password" \
    -e POSTGRES_INITDB_ARGS='--auth-host=scram-sha-256' \
    -v "${cert_dir}:/var/lib/postgresql/certs:ro" \
    "$postgres_image" \
    -c ssl=on \
    -c ssl_cert_file=/var/lib/postgresql/certs/server.crt \
    -c ssl_key_file=/var/lib/postgresql/certs/server.key \
    -c password_encryption=scram-sha-256 >/dev/null
attempt=0
until podman exec "$postgres" pg_isready -h 127.0.0.1 -U secpal -d secpal >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || exit 1
    sleep 1
done
run_api() {
    podman run --rm --network "$network" --env-file "$api_env" \
        -v "${cert_dir}/ca.crt:/run/secrets/postgresql-ca.crt:ro" \
        -v "${kek_file}:/run/secrets/secpal-kek:ro" "$image" "$@"
}
run_api php artisan migrate --force
run_api php artisan tinker --execute='throw_unless(DB::scalar("SHOW password_encryption") === "scram-sha-256"); throw_unless(DB::scalar("SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()") === true);'
run_api php artisan tenant:setup
run_api php artisan tinker --execute='app(\App\Services\RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();'
podman run -d --name "$api" --network "$network" --env-file "$api_env" \
    -v "${cert_dir}/ca.crt:/run/secrets/postgresql-ca.crt:ro" \
    -v "${kek_file}:/run/secrets/secpal-kek:ro" "$image" >/dev/null
# Keep one independent client on the isolated network. Repeated rootless network
# attach/detach is helper churn, rather than an application assertion.
podman run -d --name "$client" --network "$network" \
    -v "${port_probe}:/tmp/assert-port-closed.php:ro" "$image" sleep 300 >/dev/null
wait_for_http
podman exec "$api" secpal-http-live
assert_http /health/live 200 '"status":"alive"'
assert_http /health/ready 200 '"status":"ready"'
assert_http /v1/not-a-route 404 'Resource not found.'
assert_http /.env 404
assert_http /composer.json 404
assert_http /storage/logs/laravel.log 404
assert_http /.git/config 404
assert_http /.htaccess 404
assert_http /robots.txt.license 404
podman exec "$client" php -r '
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
podman logs "$api" >"${tmp_dir}/api.log" 2>&1
if grep -q "$log_marker" "${tmp_dir}/api.log" || ! grep -q REDACTED "${tmp_dir}/api.log"; then
    echo "Sensitive query credentials were not redacted from access logs" >&2
    exit 1
fi
for closed_port in 80 443 2019; do
    podman exec "$client" php /tmp/assert-port-closed.php "$api" "$closed_port"
done
podman stop --time 10 "$api" >/dev/null
test "$(podman inspect --format '{{.State.ExitCode}}' "$api")" -eq 0
test "$(podman inspect --format '{{.State.OOMKilled}}' "$api")" = false

echo "Container smoke and integration tests passed for ${image}"
