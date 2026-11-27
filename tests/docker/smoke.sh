#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

image=${PHP_FPM_IMAGE:?}
http_image=${NGINX_IMAGE:?}
arch=${EXPECTED_ARCH:?}
postgres_image=${POSTGRES_IMAGE:-docker.io/library/postgres:18-bookworm@sha256:1c59e2c3c818eaa0f0628f695b36e7c9e362d6b219b36a54a32df645cbd7e1af}
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
    rm -rf "$tmp_dir"
}
operation='runtime-contract'
finish_smoke() {
    if [ "$1" -ne 0 ]; then
        printf 'Native smoke operation failed: %s\n' "$operation" >&2
    fi
    cleanup
}
trap 'finish_smoke "$?"' EXIT
trap 'exit 1' HUP INT TERM
api_env="${tmp_dir}/api.env"
cert_dir="${tmp_dir}/postgres-certs"
socket_dir="${tmp_dir}/fpm-socket"
secret_root="${tmp_dir}/runtime-secrets"
bootstrap_checkout="${GITHUB_WORKSPACE:-$(pwd)}/.context/runtime-contracts"
bootstrap_commit=bf2282e3e112ac003360ecb3dff390eafe282dbd
bootstrap_source="$bootstrap_checkout/scripts/production-secret-bootstrap.php"
worker_unit=config/production/quadlet/secpal-worker-general.container
worker_source="$bootstrap_checkout/$worker_unit"
bootstrap_ini="${tmp_dir}/bootstrap.ini"
test "$(git -C "$bootstrap_checkout" rev-parse HEAD)" = "$bootstrap_commit"
test -f "$bootstrap_source"
test ! -L "$bootstrap_source"
test -f "$worker_source"
test ! -L "$worker_source"
git -C "$bootstrap_checkout" diff --quiet "$bootstrap_commit" -- scripts/production-secret-bootstrap.php "$worker_unit"
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
# The pinned deployment unit owns queue coverage. Pass only its verified queue
# names to the bounded fixture worker, with no shell evaluation of unit data.
operation='general-worker-queue-contract'
# shellcheck disable=SC2016 # PHP variables must remain literal.
general_worker_queues=$(php -r '
    $pattern = "~^Exec=php artisan queue:work --queue=([a-z][a-z0-9-]*(?:,[a-z][a-z0-9-]*)*)(?: |$)~m";
    if (preg_match_all($pattern, file_get_contents($argv[1]), $matches) !== 1) {
        exit(1);
    }
    echo $matches[1][0];
' "$worker_source")
db_user=runtime_app
port_probe="${tmp_dir}/assert-port-closed.php"
cp tests/docker/assert-port-closed.php "$port_probe"
chmod 0444 "$port_probe"
assert_http() {
    path=$1
    expected_status=$2
    expected_fragment=${3:-}
    podman exec "$client" php -r '
    $context = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 3, "follow_location" => 0]]);
    $body = @file_get_contents($argv[1], false, $context);
    $statusLine = $http_response_header[0] ?? "";
    preg_match("/\s(\d{3})\s/", $statusLine, $matches);
    $status = isset($matches[1]) ? (int) $matches[1] : 0;
    if ($status !== (int) $argv[2] || ($argv[3] !== "" && !str_contains((string) $body, $argv[3]))) {
        fwrite(STDERR, "Unexpected HTTP status or body contract (status {$status})\n");
        exit(1);
    }
    ' "http://${api}:8080${path}" "$expected_status" "$expected_fragment"
}
wait_for_http() {
    attempt=0
    while [ "$attempt" -lt 30 ]; do
        operation='http-startup-liveness'
        if assert_http /health/live 200 '"status":"alive"' >/dev/null 2>&1; then
            operation='http-startup-readiness'
            if assert_http /health/ready 200 '"status":"ready"' >/dev/null 2>&1; then
                return 0
            fi
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    if [ "$operation" = http-startup-readiness ]; then
        report_heartbeat_state
    fi
    return 1
}
operation='php-fpm-component-inventory'
tests/docker/runtime-component.sh php-fpm "$image" "$arch"
operation='nginx-component-inventory'
tests/docker/runtime-component.sh nginx "$http_image" "$arch"
operation='php-native-architecture'
podman run --rm -e EXPECTED_ARCH="$EXPECTED_ARCH" "$image" php -r '
    $header = file_get_contents("/usr/local/bin/php", false, null, 0, 20);
    $expected = getenv("EXPECTED_ARCH") === "amd64" ? 62 : 183;
    exit(substr($header, 0, 4) === "\x7fELF" && ord($header[5]) === 1
        && unpack("v", substr($header, 18, 2))[1] === $expected ? 0 : 1);
'
operation='isolated-network'
podman network create --internal --label "$owner_label" "$network" >/dev/null
operation='postgres-volume'
podman volume create --label "$owner_label" "$volume" >/dev/null
operation='fixture-secrets'
db_password=$(openssl rand -hex 24)
app_db_password=$(openssl rand -hex 24)
app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
mkdir -m 0700 "$cert_dir"
operation='postgres-tls-ca'
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -subj '/CN=SecPal container smoke CA' \
    -keyout "${cert_dir}/ca.key" -out "${cert_dir}/ca.crt" >/dev/null 2>&1
operation='postgres-tls-server-key'
openssl req -newkey rsa:2048 -nodes \
    -subj "/CN=${db_host}" \
    -keyout "${cert_dir}/server.key" -out "${cert_dir}/server.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:%s\nextendedKeyUsage=serverAuth\n' "$db_host" >"${cert_dir}/server.ext"
operation='postgres-tls-server-certificate'
openssl x509 -req -days 1 -sha256 \
    -in "${cert_dir}/server.csr" \
    -CA "${cert_dir}/ca.crt" -CAkey "${cert_dir}/ca.key" -CAcreateserial \
    -extfile "${cert_dir}/server.ext" -out "${cert_dir}/server.crt" >/dev/null 2>&1
chmod 0755 "$cert_dir"
chmod 0644 "${cert_dir}/ca.crt" "${cert_dir}/server.crt"
chmod 0600 "${cert_dir}/server.key"
operation='postgres-tls-file-ownership'
podman run --rm --user 0 -v "${cert_dir}:/certs:z" "$postgres_image" \
    chown postgres:postgres /certs/server.key /certs/server.crt /certs/ca.crt
operation='isolated-network-address'
trusted_proxy=$(podman network inspect --format '{{(index .Subnets 0).Subnet}}' "$network")
operation='synthetic-global-identity-root'
identity_root="$tmp_dir/global-identity"
mkdir -m 0700 "$identity_root"
dd if=/dev/urandom of="$identity_root/smoke.1.key" bs=32 count=1 status=none
chmod 0400 "$identity_root/smoke.1.key"
operation='runtime-bootstrap-inputs'
mkdir -m 0700 "$secret_root" "$secret_root/api" "$secret_root/database"
printf '%s' "$app_key" > "$secret_root/api/app-key"
: > "$secret_root/api/app-previous-keys"
dd if=/dev/urandom of="$secret_root/api/tenant-kek" bs=32 count=1 status=none
printf '%s' "$db_user" > "$secret_root/database/postgres-username"
printf '%s' "$app_db_password" > "$secret_root/database/postgres-password"
cp "$cert_dir/ca.crt" "$secret_root/database/postgres-ca.crt"
chmod 0400 "$secret_root/api/app-key" "$secret_root/api/app-previous-keys" "$secret_root/database/"*
chmod 0600 "$secret_root/api/tenant-kek"
printf '%s\n' 'auto_prepend_file=/run/secpal/bootstrap/production-secret-bootstrap.php' > "$bootstrap_ini"
{
    printf 'TRUSTED_PROXIES=%s\nAPP_URL=https://api.secpal.dev\nFRONTEND_URL=https://app.secpal.dev\n' "$trusted_proxy"
    printf 'CORS_ALLOWED_ORIGINS=https://app.secpal.dev\nSANCTUM_STATEFUL_DOMAINS=app.secpal.dev\n'
    printf 'ADDRESS_DATA_IMPORT_ON_SETUP=false\nMAIL_MAILER=array\nGLOBAL_IDENTITY_ROOT_DIRECTORY=/run/secpal/identity\n'
} >"$api_env"
chmod 0600 "$api_env"
operation='postgres-start'
podman run -d --label "$owner_label" --name "$postgres" --network "$network" --network-alias "$db_host" \
    -e POSTGRES_DB=secpal -e POSTGRES_USER=secpal -e POSTGRES_PASSWORD="$db_password" \
    -e POSTGRES_INITDB_ARGS='--auth-host=scram-sha-256' \
    -v "$volume:/var/lib/postgresql" \
    -v "${cert_dir}:/var/lib/postgresql/certs:ro,z" \
    "$postgres_image" \
    -c ssl=on \
    -c ssl_cert_file=/var/lib/postgresql/certs/server.crt \
    -c ssl_key_file=/var/lib/postgresql/certs/server.key \
    -c password_encryption=scram-sha-256 >/dev/null
operation='postgres-accepting-connections'
attempt=0
until podman exec "$postgres" pg_isready -h 127.0.0.1 -U secpal -d secpal >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || exit 1
    sleep 1
done
run_api() {
    podman run --rm --userns=keep-id:uid=10001,gid=10001 --user=10001:10001 \
        --network "$network" --env-file "$api_env" --read-only --cap-drop=all --security-opt=no-new-privileges \
        --mount type=tmpfs,destination=/tmp,tmpfs-mode=0700,chown=true \
        --mount type=tmpfs,destination=/config,tmpfs-mode=0750,chown=true \
        --mount type=tmpfs,destination=/data,tmpfs-mode=0750,chown=true \
        --mount type=tmpfs,destination=/app/storage,tmpfs-mode=0750,chown=true \
        --mount type=tmpfs,destination=/app/bootstrap/cache,tmpfs-mode=0750,chown=true \
        -v "$secret_root:/run/secpal/secrets:ro,z" \
        -v "$identity_root:/run/secpal/identity:ro,z" \
        -v "$bootstrap_source:/run/secpal/bootstrap/production-secret-bootstrap.php:ro,z" \
        -v "$bootstrap_ini:/usr/local/etc/php/conf.d/zzz-secpal-bootstrap.ini:ro,z" "$image" "$@" 2>/dev/null
}
settle_scheduled_work() {
    operation='scheduler-run'
    run_api php artisan schedule:run >/dev/null
    operation='general-worker-drain'
    run_api php artisan queue:work "--queue=$general_worker_queues" --stop-when-empty --tries=1 --timeout=60 --max-time=60 >/dev/null
}
report_heartbeat_state() {
    # Observe the same shared heartbeat store through the production service.
    # These CLI observations can explain an HTTP failure but cannot admit it.
    # shellcheck disable=SC2016 # PHP variables must remain literal.
    observation=$(run_api php artisan tinker --execute='
        $heartbeat = app(\App\Services\RuntimeHeartbeatService::class);
        try {
            echo "scheduler=", $heartbeat->schedulerReadiness()["status"], PHP_EOL;
        } catch (\Throwable) {
            echo "scheduler=error", PHP_EOL;
        }
        try {
            foreach ($heartbeat->queueReadiness() as $name => $state) {
                echo $name, "=", $state["status"], PHP_EOL;
            }
        } catch (\Throwable) {
            echo "queue_default_worker=error", PHP_EOL, "queue_forensics_worker=error", PHP_EOL;
        }
    ') || observation=
    if ! printf '%s\n' "$observation" | grep -Ex '(scheduler|queue_default_worker|queue_forensics_worker)=(ok|idle|missing|stale|error)' >&2; then
        printf '%s\n' 'Runtime heartbeat observation unavailable' >&2
    fi
}
operation='postgres-runtime-role'
podman exec -i "$postgres" psql -v ON_ERROR_STOP=1 -U secpal -d secpal >/dev/null 2>&1 <<SQL
CREATE ROLE runtime_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '$app_db_password';
ALTER DATABASE secpal OWNER TO runtime_app;
ALTER SCHEMA public OWNER TO runtime_app;
SQL
operation='runtime-migrations'
run_api php artisan migrate --force >/dev/null
operation='runtime-postgres-scram-and-tls'
run_api php artisan tinker --execute='throw_unless(DB::scalar("SHOW password_encryption") === "scram-sha-256"); throw_unless(DB::scalar("SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()") === true);' >/dev/null
operation='global-identity-registry-bootstrap'
run_api php artisan identity:bootstrap smoke 1 --force --no-interaction >/dev/null
operation='global-identity-root-ownership-and-access'
run_api php -r 'exit(posix_geteuid() === 10001 && posix_getegid() === 10001 && is_readable("/run/secpal/identity/smoke.1.key") && ! is_writable("/run/secpal/identity") ? 0 : 1);'
operation='tenant-key-setup'
run_api php artisan tenant:setup >/dev/null
# Drain scheduled work before creating audit rows. This exercises the real
# worker without sending the synthetic audit batch to external calendars.
settle_scheduled_work
operation='runtime-postgres-role-privileges'
run_api php artisan tinker --execute='throw_unless(DB::scalar("SELECT NOT rolsuper AND NOT rolcreatedb FROM pg_roles WHERE rolname = current_user") === true);' >/dev/null
# A real Sanctum token is captured locally; it is never printed in CI logs.
operation='auth-token-fixture'
# shellcheck disable=SC2016 # PHP variables must remain literal.
token_output=$(run_api php artisan tinker --execute='$tenant = \App\Models\TenantKey::firstOrFail(); $user = app(\App\Services\GlobalIdentity\GlobalIdentityAccounts::class)->create((new \App\Models\User)->forceFill(["tenant_id" => $tenant->id, "name" => "Container smoke", "email_verified_at" => now(), "password" => bin2hex(random_bytes(32))]), "container-smoke@secpal.dev"); echo "SMOKE-TOKEN:".$user->issueApiToken("container-smoke")->plainTextToken;')
smoke_token=$(printf '%s\n' "$token_output" | sed -n 's/^SMOKE-TOKEN://p')
test -n "$smoke_token"
# Seed only disposable smoke data; use real application permissions and encryption.
operation='employee-upload-fixture'
# shellcheck disable=SC2016 # PHP variables must remain literal.
employee_output=$(run_api php artisan tinker --execute='$tenant = \App\Models\TenantKey::firstOrFail(); $user = app(\App\Services\GlobalIdentity\GlobalIdentityAccounts::class)->find("CONTAINER-SMOKE@SECPAL.DEV"); throw_unless($user instanceof \App\Models\User); $registrar = app(\Spatie\Permission\PermissionRegistrar::class); $registrar->setPermissionsTeamId($tenant->id); foreach (["employee_document.read", "employee_document.write"] as $permission) { $user->givePermissionTo(\App\Models\Permission::findOrCreate($permission, "sanctum")); } $registrar->forgetCachedPermissions(); $legal = \App\Models\LegalEntity::forceCreate(["tenant_id" => $tenant->id, "name" => "Container smoke"]); $site = \App\Models\Establishment::forceCreate(["tenant_id" => $tenant->id, "legal_entity_id" => $legal->id, "name" => "Container smoke"]); $employee = \App\Models\Employee::withoutEvents(fn () => \App\Models\Employee::forceCreate(["tenant_id" => $tenant->id, "legal_entity_id" => $legal->id, "establishment_id" => $site->id, "employee_number" => "CONTAINER-SMOKE", "first_name" => "Container", "last_name" => "Smoke", "contract_type" => "full_time", "status" => "applicant"])); echo "SMOKE-EMPLOYEE:".$employee->id;')
smoke_employee=$(printf '%s\n' "$employee_output" | sed -n 's/^SMOKE-EMPLOYEE://p')
test -n "$smoke_employee"
operation='activity-hash-fixture'
run_api php artisan tinker --execute='\App\Models\Activity::create(["tenant_id" => \App\Models\TenantKey::firstOrFail()->id, "log_name" => "system", "description" => "container queue smoke", "properties" => []]);' >/dev/null
operation='hash-chain-worker-drain'
run_api php artisan queue:work --queue=activity-hash-chain --stop-when-empty --tries=1 --timeout=60 --max-time=60 >/dev/null
operation='activity-hash-and-failed-jobs'
# shellcheck disable=SC2016 # PHP variables must remain literal.
run_api php artisan tinker --execute='$activity = \App\Models\Activity::where("description", "container queue smoke")->firstOrFail(); throw_unless(preg_match("/^[a-f0-9]{64}$/", (string) $activity->event_hash) === 1); throw_unless(DB::table("failed_jobs")->count() === 0);' >/dev/null
operation='php-fpm-socket-directory'
mkdir -m 0700 "$socket_dir"
operation='php-fpm-start'
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
        -v "$identity_root:/run/secpal/identity:ro,z" \
    -v "$bootstrap_source:/run/secpal/bootstrap/production-secret-bootstrap.php:ro,z" \
    -v "$bootstrap_ini:/usr/local/etc/php/conf.d/zzz-secpal-bootstrap.ini:ro,z" "$image" >/dev/null
operation='nginx-start'
podman run -d --label "$owner_label" --name "$api" --network "$network" \
    --userns=keep-id:uid=10001,gid=10001 --user=10001:10001 \
    --read-only --cap-drop=all --security-opt=no-new-privileges \
    --mount type=tmpfs,destination=/tmp,tmpfs-mode=0700,chown=true \
    -v "$socket_dir:/run/secpal-fpm:ro,z" "$http_image" >/dev/null
# Keep one independent client on the isolated network. Repeated rootless network
# attach/detach is helper churn, rather than an application assertion.
operation='nginx-network-address'
http_address=$(podman inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$api")
test -n "$http_address"
# Stopping FPM must not make the HTTP assertion depend on container DNS churn.
operation='http-client-start'
podman run -d --label "$owner_label" --name "$client" --network "$network" \
    --add-host "$api:$http_address" \
    -v "${port_probe}:/tmp/assert-port-closed.php:ro,z" "$image" sleep 300 >/dev/null
wait_for_http
operation='nginx-healthcheck'
podman exec "$api" secpal-http-live
operation='http-liveness'
assert_http /health/live 200 '"status":"alive"'
operation='http-readiness'
assert_http /health/ready 200 '"status":"ready"'
operation='http-root-denied'
assert_http / 404
operation='http-path-info-denied'
assert_http /index.php/.env 404
operation='http-arbitrary-php-denied'
assert_http /arbitrary.php 404
operation='http-private-fpm-ping-denied'
assert_http /__secpal_fpm_ping 404
operation='authenticated-http-and-document-transfer'
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
    function requireSmoke(bool $passed, string $operation): void {
        if (!$passed) {
            fwrite(STDERR, "Native HTTP operation failed: ".$operation.PHP_EOL);
            exit(1);
        }
    }
    [$status] = probe("/v1/me");
    requireSmoke($status === 401, "anonymous-current-user");
    [$status, $body] = probe("/v1/me", headers: ["Authorization: Bearer ".$argv[2]]);
    requireSmoke($status === 200 && str_contains($body, "container-smoke@secpal.dev"), "authenticated-current-user");
    $browser = ["Origin: https://app.secpal.dev", "X-Forwarded-Proto: https"];
    [$status, , $headers] = probe("/v1/me", "OPTIONS", [...$browser, "Access-Control-Request-Method: GET"]);
    requireSmoke($status === 204 && str_contains($headers, "access-control-allow-origin: https://app.secpal.dev")
        && str_contains($headers, "access-control-allow-credentials: true"), "credentialed-cors-preflight");
    [$status, , $headers] = probe("/sanctum/csrf-cookie", headers: $browser);
    requireSmoke($status === 204 && str_contains($headers, "set-cookie: xsrf-token=")
        && str_contains($headers, "secure") && str_contains($headers, "samesite=lax"), "secure-csrf-cookie");
    [$status] = probe("/v1/auth/login", "POST", $browser, "{}");
    requireSmoke($status === 419, "login-csrf-rejection");
    [$status] = probe("/v1/auth/token", "POST", [], str_repeat("x", 12*1024*1024+1));
    requireSmoke($status === 413, "request-size-limit");
    // Real multipart upload, encrypted private storage and chunked client reads
    // through the unchanged Laravel front controller; no synthetic HTTP route.
    $pdf = "%PDF-1.4\n%".str_repeat("runtime-transfer", 65536)."\n%%EOF\n";
    $file = @tempnam(sys_get_temp_dir(), "runtime-upload-");
    requireSmoke($file !== false, "document-upload-fixture-file");
    try {
        requireSmoke(@file_put_contents($file, $pdf) === strlen($pdf), "document-upload-fixture-write");
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
        requireSmoke($status === 201, "multipart-upload");
        $document = is_string($body) ? (json_decode($body, true)["data"] ?? null) : null;
        requireSmoke(is_array($document) && isset($document["id"], $document["file_size"]), "document-response-shape");
        requireSmoke(!isset($document["file_path"]) && $document["file_size"] === strlen($pdf), "private-document-metadata");
        $download = $path."/".$document["id"]."/download";
        [$status] = probe($download);
        requireSmoke($status === 401, "anonymous-document-download");
        $stream = @fopen($argv[1].$download, "rb", false, stream_context_create(["http" => [
            "timeout" => 15, "follow_location" => 0,
            "header" => "Authorization: Bearer ".$argv[2]."\r\nConnection: close\r\n",
        ]]));
        requireSmoke(is_resource($stream) && str_contains($http_response_header[0] ?? "", " 200 "), "authorized-document-download");
        $hash = hash_init("sha256"); $length = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            requireSmoke($chunk !== false && ($chunk !== "" || feof($stream)), "document-download-stream");
            $length += strlen($chunk); hash_update($hash, $chunk);
        }
        fclose($stream);
        requireSmoke($length === strlen($pdf) && hash_final($hash) === hash("sha256", $pdf), "document-download-integrity");
    } finally {
        unlink($file);
    }
' "http://${api}:8080" "$smoke_token" "$smoke_employee"
operation='host-socket-ownership'
test "$(stat -c '%u:%g' "$socket_dir")" = "$(id -u):$(id -g)"
operation='fpm-socket-ownership'
test "$(podman exec "$fpm" stat -c '%u:%g' /run/secpal-fpm/api.sock)" = 10001:10001
operation='http-unmatched-route'
assert_http /v1/not-a-route 404 'Resource not found.'
operation='http-env-denied'
assert_http /.env 404
operation='http-composer-denied'
assert_http /composer.json 404
operation='http-storage-denied'
assert_http /storage/logs/laravel.log 404
operation='http-git-denied'
assert_http /.git/config 404
operation='http-htaccess-denied'
assert_http /.htaccess 404
operation='http-license-sidecar-denied'
assert_http /robots.txt.license 404
operation='http-forwarded-https-hsts'
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
operation='http-onboarding-query-redaction-probe'
assert_http "/v1/onboarding/validate-token?token=${log_marker}&email=${log_marker}%40${email_domain}" 422
operation='http-verification-query-redaction-probe'
assert_http "/v1/auth/email/verify/00000000-0000-4000-8000-000000000001/synthetic-hash?expires=2000000000&signature=${log_marker}" 403
operation='nginx-log-redaction'
podman logs "$api" >"${tmp_dir}/api.log" 2>&1
if grep -q "$log_marker" "${tmp_dir}/api.log"; then
    echo "Sensitive query credentials were not redacted from access logs" >&2
    exit 1
fi
operation='nginx-upstream-telemetry'
grep -q '"upstream_status"' "${tmp_dir}/api.log"
operation='fpm-local-ping'
podman exec "$fpm" php /usr/local/bin/secpal-fpm-live.php
operation='fpm-socket-mode'
test "$(podman exec "$fpm" stat -c '%a' /run/secpal-fpm/api.sock)" = 660
operation='fpm-no-tcp-listener'
podman exec "$client" php /tmp/assert-port-closed.php "$fpm" 9000
operation='fpm-graceful-shutdown'
podman stop --time 10 "$fpm" >/dev/null
test "$(podman inspect --format '{{.State.ExitCode}}' "$fpm")" -eq 0
operation='http-failed-upstream'
assert_http /health/live 502
operation='nginx-healthcheck-failed-upstream'
if podman exec "$api" secpal-http-live; then exit 1; fi
operation='nginx-extra-ports-closed'
for closed_port in 80 443; do
    podman exec "$client" php /tmp/assert-port-closed.php "$api" "$closed_port"
done
operation='nginx-graceful-shutdown'
podman stop --time 10 "$api" >/dev/null
test "$(podman inspect --format '{{.State.ExitCode}}' "$api")" -eq 0
test "$(podman inspect --format '{{.State.OOMKilled}}' "$api")" = false

echo "Container smoke and integration tests passed for ${image}"
