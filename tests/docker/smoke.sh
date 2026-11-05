#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

runtime_set=${RUNTIME_SET:-legacy}
case "$runtime_set" in
  legacy) image=${IMAGE_TAG:-secpal-api:test} ;;
  pair) image=${PHP_FPM_IMAGE:?}; http_image=${NGINX_IMAGE:?} ;;
  *) echo 'Unknown runtime set' >&2; exit 1 ;;
esac
postgres_image=${POSTGRES_IMAGE:-postgres:18-bookworm@sha256:1c59e2c3c818eaa0f0628f695b36e7c9e362d6b219b36a54a32df645cbd7e1af}
tmp_dir=$(mktemp -d)
suffix=$(basename "$tmp_dir")
suffix=${suffix#tmp.}
owner_label="secpal.dev/smoke=$suffix"
volume="secpal-api-smoke-data-$suffix"
network="secpal-api-smoke-${suffix}"
postgres="secpal-postgres-${suffix}"
api="secpal-api-${suffix}"
client="secpal-client-${suffix}"
fpm="secpal-fpm-${suffix}"
sqlite_probe="database/container-smoke-${suffix}.sqlite"
if test -e "$sqlite_probe"; then
    echo "Refusing to overwrite existing SQLite smoke probe: ${sqlite_probe}" >&2
    exit 1
fi
cleanup() {
    for container in "$client" "$api" "$fpm" "$postgres"; do
        if [ "$(podman inspect --format '{{index .Config.Labels "secpal.dev/smoke"}}' "$container" 2>/dev/null)" = "$suffix" ]; then
            podman rm -f "$container" >/dev/null 2>&1 || true
        fi
    done
    if [ "$(podman volume inspect --format '{{index .Labels "secpal.dev/smoke"}}' "$volume" 2>/dev/null)" = "$suffix" ]; then
        podman volume rm "$volume" >/dev/null 2>&1 || true
    fi
    if [ "$(podman network inspect --format '{{index .Labels "secpal.dev/smoke"}}' "$network" 2>/dev/null)" = "$suffix" ]; then
        podman network rm "$network" >/dev/null 2>&1 || true
    fi
    rm -f "$sqlite_probe"
    rm -rf "$tmp_dir"
}
trap cleanup EXIT HUP INT TERM
kek_file="${tmp_dir}/kek"
api_env="${tmp_dir}/api.env"
cert_dir="${tmp_dir}/postgres-certs"
socket_dir="${tmp_dir}/fpm-socket"
secret_root="${tmp_dir}/runtime-secrets"
bootstrap_checkout="${GITHUB_WORKSPACE:-$(pwd)}/.context/runtime-contracts"
bootstrap_commit=bf2282e3e112ac003360ecb3dff390eafe282dbd
bootstrap_source="$bootstrap_checkout/scripts/production-secret-bootstrap.php"
bootstrap_ini="${tmp_dir}/bootstrap.ini"
db_host="$postgres"
db_user=secpal
if [ "$runtime_set" = pair ]; then
    test "$(git -C "$bootstrap_checkout" rev-parse HEAD)" = "$bootstrap_commit"
    test -f "$bootstrap_source"
    test ! -L "$bootstrap_source"
    git -C "$bootstrap_checkout" diff --exit-code "$bootstrap_commit" -- scripts/production-secret-bootstrap.php
    # The authenticated bootstrap owns this hostname. Read its single literal
    # declaration instead of maintaining a duplicate DNS contract here.
    # shellcheck disable=SC2016 # PHP variables must remain literal.
    db_host=$(php -r '
        $quote = chr(39);
        $pattern = "/".$quote."DB_HOST".$quote."\\s*=>\\s*".$quote."([a-z0-9.-]+)".$quote."/";
        if (preg_match_all($pattern, file_get_contents($argv[1]), $matches) !== 1) {
            fwrite(STDERR, "Qualified bootstrap must declare exactly one DB_HOST.\n");
            exit(1);
        }
        echo $matches[1][0];
    ' "$bootstrap_source")
    db_user=runtime_app
fi
port_probe="${tmp_dir}/assert-port-closed.php"
cp tests/docker/assert-port-closed.php "$port_probe"
chmod 0444 "$port_probe"
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
if [ "$runtime_set" = legacy ]; then
# Compare actual binaries to the single authoritative, immutable runtime input.
# PHP 8.4.27 is the minimum security patch level for the PHP2Shell repair.
# shellcheck disable=SC2016 # PHP variables must not be expanded by the shell.
expected_runtime=$(php -r '
    $count = preg_match_all(
        "~^ARG FRANKENPHP_IMAGE=docker\\.io/dunglas/frankenphp:([0-9]+\\.[0-9]+\\.[0-9]+)-php(8\\.4\\.[0-9]+)-alpine@sha256:[a-f0-9]{64}$~m",
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
assert_output_starts_with "$frankenphp_version" "FrankenPHP v${expected_frankenphp} "
# Check the actual stable-Alpine fixes independently of the Containerfile recipe.
podman run --rm "$image" sh -eu -c '
    apk info --installed curl=8.23.0-r0
    apk info --installed libcurl=8.23.0-r0
    apk info --installed brotli-libs=1.2.1-r0
'
# Build-only headers must be absent from the actual final runtime, not just its recipe.
podman run --rm "$image" sh -eu -c '
    packages=$(apk info)
    test -n "$packages"
    for package in linux-headers musl-dev libc-dev; do
        if printf "%s\n" "$packages" | grep -Fx "$package" >/dev/null; then
            echo "Build-only runtime package remains: $package" >&2
            exit 1
        fi
    done
    test ! -e /usr/include/linux/bpf.h
    test ! -e /usr/include/stdio.h
    test ! -e /usr/bin/gencat
'
# Exercise required extensions under the deployment privilege/read-only boundary.
podman run --rm --read-only --cap-drop=all --security-opt=no-new-privileges "$image" php -r '
    foreach (["bcmath", "curl", "gettext", "intl", "mbstring", "Zend OPcache",
              "pcntl", "pdo_pgsql", "pgsql", "sodium", "xml", "zip"] as $extension) {
        if (!extension_loaded($extension)) {
            fwrite(STDERR, "Missing runtime extension: $extension\n");
            exit(1);
        }
    }
'
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

else
    tests/docker/runtime-component.sh php-fpm "$image" "${EXPECTED_ARCH:?}"
    tests/docker/runtime-component.sh nginx "$http_image" "$EXPECTED_ARCH"
fi

podman network create --internal --label "$owner_label" "$network" >/dev/null
podman volume create --label "$owner_label" "$volume" >/dev/null
db_password=$(openssl rand -hex 24)
app_db_password="$db_password"
if [ "$runtime_set" = pair ]; then app_db_password=$(openssl rand -hex 24); fi
app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
mkdir -m 0700 "$cert_dir"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -subj '/CN=SecPal container smoke CA' \
    -keyout "${cert_dir}/ca.key" -out "${cert_dir}/ca.crt" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes \
    -subj "/CN=${db_host}" \
    -keyout "${cert_dir}/server.key" -out "${cert_dir}/server.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:%s\nextendedKeyUsage=serverAuth\n' "$db_host" >"${cert_dir}/server.ext"
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
if [ "$runtime_set" = legacy ]; then
    podman run --rm --user 0 -v "${kek_file}:/kek" "$image" chown 10001:10001 /kek
fi
trusted_proxy=$(podman network inspect --format '{{(index .Subnets 0).Subnet}}' "$network")
if [ "$runtime_set" = pair ]; then
    mkdir -m 0700 "$secret_root" "$secret_root/api" "$secret_root/database"
    printf '%s' "$app_key" > "$secret_root/api/app-key"
    : > "$secret_root/api/app-previous-keys"
    cp "$kek_file" "$secret_root/api/tenant-kek"
    printf '%s' "$db_user" > "$secret_root/database/postgres-username"
    printf '%s' "$app_db_password" > "$secret_root/database/postgres-password"
    cp "$cert_dir/ca.crt" "$secret_root/database/postgres-ca.crt"
    chmod 0400 "$secret_root/api/app-key" "$secret_root/api/app-previous-keys" "$secret_root/database/"*
    chmod 0600 "$secret_root/api/tenant-kek"
    printf '%s\n' 'auto_prepend_file=/run/secpal/bootstrap/production-secret-bootstrap.php' > "$bootstrap_ini"
fi
{
    printf 'TRUSTED_PROXIES=%s\nAPP_URL=https://api.secpal.dev\nFRONTEND_URL=https://app.secpal.dev\n' "$trusted_proxy"
    printf 'CORS_ALLOWED_ORIGINS=https://app.secpal.dev\nSANCTUM_STATEFUL_DOMAINS=app.secpal.dev\n'
    printf 'ADDRESS_DATA_IMPORT_ON_SETUP=false\nMAIL_MAILER=array\n'
    if [ "$runtime_set" = legacy ]; then
        printf 'APP_KEY=%s\n' "$app_key"
        printf 'DB_CONNECTION=pgsql\nDB_HOST=%s\nDB_PORT=5432\n' "$db_host"
        printf 'DB_DATABASE=secpal\nDB_USERNAME=%s\nDB_PASSWORD=%s\n' "$db_user" "$app_db_password"
        printf 'DB_SSLMODE=verify-full\nDB_SSLROOTCERT=/run/secrets/postgresql-ca.crt\n'
        printf 'KEK_PATH=/run/secrets/secpal-kek\n'
    fi
} >"$api_env"
chmod 0600 "$api_env"
podman run -d --label "$owner_label" --name "$postgres" --network "$network" --network-alias "$db_host" \
    -e POSTGRES_DB=secpal -e POSTGRES_USER=secpal -e POSTGRES_PASSWORD="$db_password" \
    -e POSTGRES_INITDB_ARGS='--auth-host=scram-sha-256' \
    -v "$volume:/var/lib/postgresql" \
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
    if [ "$runtime_set" = pair ]; then
        podman run --rm --userns=keep-id:uid=10001,gid=10001 --user=10001:10001 \
            --network "$network" --env-file "$api_env" --read-only --cap-drop=all --security-opt=no-new-privileges \
            --mount type=tmpfs,destination=/tmp,tmpfs-mode=0700,chown=true \
            --mount type=tmpfs,destination=/config,tmpfs-mode=0750,chown=true \
            --mount type=tmpfs,destination=/data,tmpfs-mode=0750,chown=true \
            --mount type=tmpfs,destination=/app/storage,tmpfs-mode=0750,chown=true \
            --mount type=tmpfs,destination=/app/bootstrap/cache,tmpfs-mode=0750,chown=true \
            -v "$secret_root:/run/secpal/secrets:ro,z" \
            -v "$bootstrap_source:/run/secpal/bootstrap/production-secret-bootstrap.php:ro,z" \
            -v "$bootstrap_ini:/usr/local/etc/php/conf.d/zzz-secpal-bootstrap.ini:ro,z" "$image" "$@"
        return
    fi
    podman run --rm --network "$network" --env-file "$api_env" \
        -v "${cert_dir}/ca.crt:/run/secrets/postgresql-ca.crt:ro" \
        -v "${kek_file}:/run/secrets/secpal-kek:ro" "$image" "$@"
}
if [ "$runtime_set" = pair ]; then
    podman exec -i "$postgres" psql -v ON_ERROR_STOP=1 -U secpal -d secpal <<SQL
CREATE ROLE runtime_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '$app_db_password';
ALTER DATABASE secpal OWNER TO runtime_app;
ALTER SCHEMA public OWNER TO runtime_app;
SQL
fi
run_api php artisan migrate --force
run_api php artisan tinker --execute='throw_unless(DB::scalar("SHOW password_encryption") === "scram-sha-256"); throw_unless(DB::scalar("SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()") === true);'
run_api php artisan tenant:setup
if [ "$runtime_set" = legacy ]; then
    run_api php artisan tinker --execute='app(\App\Services\RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();'
else
    run_api php artisan schedule:run
    run_api php artisan tinker --execute='throw_unless(DB::scalar("SELECT NOT rolsuper AND NOT rolcreatedb FROM pg_roles WHERE rolname = current_user") === true);'
    # A real Sanctum token is captured locally; it is never printed in CI logs.
    # shellcheck disable=SC2016 # PHP variables must remain literal.
    token_output=$(run_api php artisan tinker --execute='$tenant = \App\Models\TenantKey::firstOrFail(); $user = \App\Models\User::forceCreate(["tenant_id" => $tenant->id, "name" => "Container smoke", "email" => "container-smoke@secpal.dev", "email_verified_at" => now(), "password" => bin2hex(random_bytes(32))]); echo "SMOKE-TOKEN:".$user->issueApiToken("container-smoke")->plainTextToken;')
    smoke_token=$(printf '%s\n' "$token_output" | sed -n 's/^SMOKE-TOKEN://p')
    test -n "$smoke_token"
    # Seed only disposable smoke data; use real application permissions and encryption.
    # shellcheck disable=SC2016 # PHP variables must remain literal.
    employee_output=$(run_api php artisan tinker --execute='$tenant = \App\Models\TenantKey::firstOrFail(); $user = \App\Models\User::where("email", "container-smoke@secpal.dev")->firstOrFail(); $registrar = app(\Spatie\Permission\PermissionRegistrar::class); $registrar->setPermissionsTeamId($tenant->id); foreach (["employee_document.read", "employee_document.write"] as $permission) { $user->givePermissionTo(\App\Models\Permission::findOrCreate($permission, "sanctum")); } $registrar->forgetCachedPermissions(); $legal = \App\Models\LegalEntity::forceCreate(["tenant_id" => $tenant->id, "name" => "Container smoke"]); $site = \App\Models\Establishment::forceCreate(["tenant_id" => $tenant->id, "legal_entity_id" => $legal->id, "name" => "Container smoke"]); $employee = \App\Models\Employee::withoutEvents(fn () => \App\Models\Employee::forceCreate(["tenant_id" => $tenant->id, "legal_entity_id" => $legal->id, "establishment_id" => $site->id, "employee_number" => "CONTAINER-SMOKE", "first_name" => "Container", "last_name" => "Smoke", "contract_type" => "full_time", "status" => "applicant"])); echo "SMOKE-EMPLOYEE:".$employee->id;')
    smoke_employee=$(printf '%s\n' "$employee_output" | sed -n 's/^SMOKE-EMPLOYEE://p')
    test -n "$smoke_employee"
    run_api php artisan tinker --execute='\App\Models\Activity::create(["tenant_id" => \App\Models\TenantKey::firstOrFail()->id, "log_name" => "system", "description" => "container queue smoke", "properties" => []]);'
    run_api php artisan queue:work --queue=activity-hash-chain --stop-when-empty --tries=1 --timeout=60 --max-time=60
    # shellcheck disable=SC2016 # PHP variables must remain literal.
    run_api php artisan tinker --execute='$activity = \App\Models\Activity::where("description", "container queue smoke")->firstOrFail(); throw_unless(preg_match("/^[a-f0-9]{64}$/", (string) $activity->event_hash) === 1); throw_unless(DB::table("failed_jobs")->count() === 0);'
fi
if [ "$runtime_set" = legacy ]; then
podman run -d --label "$owner_label" --name "$api" --network "$network" --env-file "$api_env" \
    --read-only --cap-drop=all --security-opt=no-new-privileges \
    --tmpfs /tmp:rw,nosuid,nodev,noexec \
    --mount type=tmpfs,destination=/config,tmpfs-mode=0750,chown=true \
    --mount type=tmpfs,destination=/data,tmpfs-mode=0750,chown=true \
    --mount type=tmpfs,destination=/app/storage,tmpfs-mode=0750,chown=true \
    --mount type=tmpfs,destination=/app/bootstrap/cache,tmpfs-mode=0750,chown=true \
    -v "${cert_dir}/ca.crt:/run/secrets/postgresql-ca.crt:ro" \
    -v "${kek_file}:/run/secrets/secpal-kek:ro" "$image" >/dev/null
else
    mkdir -m 0700 "$socket_dir"
    podman run -d --label "$owner_label" --name "$fpm" --network "$network" --env-file "$api_env" \
        --userns=keep-id:uid=10001,gid=10001 --user=10001:10001 \
        --read-only --cap-drop=all --security-opt=no-new-privileges \
        --mount type=tmpfs,destination=/tmp,tmpfs-mode=0700,chown=true \
        --mount type=tmpfs,destination=/config,tmpfs-mode=0750,chown=true \
        --mount type=tmpfs,destination=/data,tmpfs-mode=0750,chown=true \
        --mount type=tmpfs,destination=/app/storage,tmpfs-mode=0750,chown=true \
        --mount type=tmpfs,destination=/app/bootstrap/cache,tmpfs-mode=0750,chown=true \
        -v "$socket_dir:/run/secpal-fpm:rw,z" \
        -v "$secret_root:/run/secpal/secrets:ro,z" \
        -v "$bootstrap_source:/run/secpal/bootstrap/production-secret-bootstrap.php:ro,z" \
        -v "$bootstrap_ini:/usr/local/etc/php/conf.d/zzz-secpal-bootstrap.ini:ro,z" "$image" >/dev/null
    podman run -d --label "$owner_label" --name "$api" --network "$network" \
        --userns=keep-id:uid=10001,gid=10001 --user=10001:10001 \
        --read-only --cap-drop=all --security-opt=no-new-privileges \
        --mount type=tmpfs,destination=/tmp,tmpfs-mode=0700,chown=true \
        -v "$socket_dir:/run/secpal-fpm:ro,z" "$http_image" >/dev/null
fi
# Keep one independent client on the isolated network. Repeated rootless network
# attach/detach is helper churn, rather than an application assertion.
podman run -d --label "$owner_label" --name "$client" --network "$network" \
    -v "${port_probe}:/tmp/assert-port-closed.php:ro" "$image" sleep 300 >/dev/null
wait_for_http
podman exec "$api" secpal-http-live
assert_http /health/live 200 '"status":"alive"'
assert_http /health/ready 200 '"status":"ready"'
if [ "$runtime_set" = pair ]; then
    assert_http /index.php/.env 404
    assert_http /arbitrary.php 404
    assert_http /__secpal_fpm_ping 404
    podman exec "$client" php -r '
        function probe(string $path, string $method = "GET", array $headers = [], string $content = ""): array {
            global $argv;
            $context = stream_context_create(["http" => [
                "ignore_errors" => true, "timeout" => 5, "follow_location" => 0,
                "method" => $method, "content" => $content,
                "header" => implode("\r\n", ["Accept: application/json", "Connection: close",
                    "Content-Type: application/json", ...$headers]),
            ]]);
            $body = @file_get_contents($argv[1].$path, false, $context);
            preg_match("/\\s(\\d{3})\\s/", $http_response_header[0] ?? "", $status);
            return [(int) ($status[1] ?? 0), (string) $body, strtolower(implode("\n", $http_response_header ?? []))];
        }
        [$status] = probe("/v1/me");
        if ($status !== 401) { exit(1); }
        [$status, $body] = probe("/v1/me", headers: ["Authorization: Bearer ".$argv[2]]);
        if ($status !== 200 || !str_contains($body, "container-smoke@secpal.dev")) { exit(1); }
        $browser = ["Origin: https://app.secpal.dev", "X-Forwarded-Proto: https"];
        [$status, , $headers] = probe("/v1/me", "OPTIONS", [...$browser, "Access-Control-Request-Method: GET"]);
        if ($status !== 204 || !str_contains($headers, "access-control-allow-origin: https://app.secpal.dev")
            || !str_contains($headers, "access-control-allow-credentials: true")) { exit(1); }
        [$status, , $headers] = probe("/sanctum/csrf-cookie", headers: $browser);
        if ($status !== 204 || !str_contains($headers, "set-cookie: xsrf-token=")
            || !str_contains($headers, "secure") || !str_contains($headers, "samesite=lax")) { exit(1); }
        [$status] = probe("/v1/auth/login", "POST", $browser, "{}");
        if ($status !== 419) { exit(1); }
        [$status] = probe("/v1/auth/token", "POST", [], str_repeat("x", 12*1024*1024+1));
        if ($status !== 413) { exit(1); }
        // Real multipart upload, encrypted private storage and chunked client reads
        // through the unchanged Laravel front controller; no synthetic HTTP route.
        $pdf = "%PDF-1.4\n%".str_repeat("runtime-transfer", 65536)."\n%%EOF\n";
        $file = tempnam(sys_get_temp_dir(), "runtime-upload-");
        try {
            file_put_contents($file, $pdf);
            $path = "/v1/employees/".$argv[3]."/documents";
            $upload = curl_init($argv[1].$path);
            curl_setopt_array($upload, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ["Accept: application/json", "Authorization: Bearer ".$argv[2]],
                CURLOPT_POSTFIELDS => ["file" => new CURLFile($file, "application/pdf", "runtime.pdf"),
                    "title" => "Container smoke", "document_type" => "contract", "visible_to_employee" => "1"],
            ]);
            $body = curl_exec($upload);
            $status = curl_getinfo($upload, CURLINFO_RESPONSE_CODE);
            curl_close($upload);
            if ($status !== 201) { fwrite(STDERR, "Multipart upload status: ".$status."\n"); exit(1); }
            $document = json_decode($body, true, flags: JSON_THROW_ON_ERROR)["data"];
            if (isset($document["file_path"]) || $document["file_size"] !== strlen($pdf)) { exit(1); }
            $download = $path."/".$document["id"]."/download";
            [$status] = probe($download);
            if ($status !== 401) { exit(1); }
            $stream = fopen($argv[1].$download, "rb", false, stream_context_create(["http" => [
                "timeout" => 15, "follow_location" => 0,
                "header" => "Authorization: Bearer ".$argv[2]."\r\nConnection: close\r\n",
            ]]));
            if (!is_resource($stream) || !str_contains($http_response_header[0], " 200 ")) { exit(1); }
            $hash = hash_init("sha256"); $length = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === "" && !feof($stream))) { exit(1); }
                $length += strlen($chunk); hash_update($hash, $chunk);
            }
            fclose($stream);
            if ($length !== strlen($pdf) || hash_final($hash) !== hash("sha256", $pdf)) { exit(1); }
        } finally {
            unlink($file);
        }
    ' "http://${api}:8080" "$smoke_token" "$smoke_employee"
    test "$(stat -c '%u:%g' "$socket_dir")" = "$(id -u):$(id -g)"
    test "$(podman exec "$fpm" stat -c '%u:%g' /run/secpal-fpm/api.sock)" = 10001:10001
fi
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
if grep -q "$log_marker" "${tmp_dir}/api.log"; then
    echo "Sensitive query credentials were not redacted from access logs" >&2
    exit 1
fi
if [ "$runtime_set" = legacy ]; then
    grep -q REDACTED "${tmp_dir}/api.log"
else
    grep -q '"upstream_status"' "${tmp_dir}/api.log"
    podman exec "$fpm" php /usr/local/bin/secpal-fpm-live.php
    test "$(podman exec "$fpm" stat -c '%a' /run/secpal-fpm/api.sock)" = 660
    podman exec "$client" php /tmp/assert-port-closed.php "$fpm" 9000
    podman stop --time 10 "$fpm" >/dev/null
    test "$(podman inspect --format '{{.State.ExitCode}}' "$fpm")" -eq 0
    assert_http /health/live 502
    if podman exec "$api" secpal-http-live; then exit 1; fi
fi
for closed_port in 80 443 2019; do
    podman exec "$client" php /tmp/assert-port-closed.php "$api" "$closed_port"
done
podman stop --time 10 "$api" >/dev/null
test "$(podman inspect --format '{{.State.ExitCode}}' "$api")" -eq 0
test "$(podman inspect --format '{{.State.OOMKilled}}' "$api")" = false

echo "Container smoke and integration tests passed for ${image}"
