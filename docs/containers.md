<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# API container

SecPal uses one production API image for HTTP, queues, scheduling, and administration. Docker is the reference hosting path, while Laravel remains deployment-neutral and can run outside containers.

## Architecture

The pinned base is `dunglas/frankenphp:1.12.6-php8.4.23-bookworm` at multi-architecture digest `sha256:79b347211bfec90d6a1373c4956a7d3832c8248a2ff2d76bd0b677f37284d32f`. It provides FrankenPHP 1.12.6, PHP 8.4.23, Debian 12, and verified `amd64`/`arm64` manifests. The Composer 2.10.2 build stage is also pinned to its multi-architecture digest. The Debian variant avoids Alpine/musl compatibility differences.

FrankenPHP serves only `/app/public` in Classic Mode on HTTP port `8080`. Worker Mode, Octane, automatic HTTPS, ACME, and Caddy's admin API are disabled. The base image's OCI metadata still declares ports 80, 443, and 2019, but no process listens there. The `secpal` user (UID/GID `10001`) runs without extra capabilities; root owns source, while `storage` and `bootstrap/cache` are writable by that UID/GID.

Extensions are `bcmath`, `curl`, `gettext`, `intl`, `mbstring`, `opcache`, `pcntl`, `pdo_pgsql`, `pgsql`, `sodium`, XML, `zip`, and native PhpRedis 6.3.0. Python 3 and hash-locked `opentimestamps-client` 0.7.2 support the existing queue. Node.js, Redis, and Valkey servers are absent.

## Build and HTTP

Build without `.env`, `APP_KEY`, KEK, PostgreSQL, or a Redis-compatible server:

```bash
docker build -t secpal-api:test .
docker run --rm -p 8080:8080 -v secpal-api-storage:/app/storage/app/private -e APP_KEY -e DB_CONNECTION=pgsql -e DB_HOST -e DB_DATABASE -e DB_USERNAME -e DB_PASSWORD secpal-api:test
```

The default command is `frankenphp run --config /etc/frankenphp/Caddyfile`. No startup step generates secrets or runs migrations. Production must mount persistent storage at `/app/storage/app/private` and share it with roles that access uploads; otherwise database metadata can outlive container-local blobs.

Set `TRUSTED_PROXIES` to a comma-separated allowlist of proxy IP addresses or CIDRs only when deployed behind trusted proxies. It is empty by default.

## Roles

Override the default command for the non-HTTP roles:

```bash
# Exactly one instance per installation; enforced by the future deployment stack.
php artisan queue:work --queue=activity-hash-chain --sleep=3 --tries=3
# Independently scalable where application workload permits.
php artisan queue:work --queue=merkle,opentimestamp,default --sleep=3 --tries=3
# Exactly one instance per installation; enforced by the future deployment stack.
php artisan schedule:work
# Administrative examples.
php artisan about
php artisan schedule:list
php artisan migrate --force
```

Migration is an explicit deployment operation after a backup or restore point, never an entrypoint or healthcheck action.

## PostgreSQL, Valkey, and secrets

PostgreSQL remains the persistent primary database and is external to the image; normal `DB_*` variables configure it. PhpRedis communicates over RESP with Redis-compatible servers, including the validated Valkey version. Redis-compatible services remain optional and external. Existing `REDIS_*` variables remain the contract, and database-backed queue, cache, and session defaults do not change.

The KEK is an external raw 32-byte secret. Configure `KEK_PATH`, mount the file read-only where practical, make it readable by UID `10001`, and set mode `0600`. The default is `storage/app/keys/kek.key`; `/run/secrets/secpal-kek` is optional. Never place the KEK, `APP_KEY`, `.env`, credentials, tokens, private certificates, or API keys in the image; it creates none of them.

## Health and validation

`GET /health/live` needs neither PostgreSQL nor Valkey. `GET /health/ready` checks the configured database, tenant key, KEK readability, scheduler heartbeat, and relevant worker heartbeats. `secpal-http-live` is the reusable liveness probe. `HEALTHCHECK NONE` clears the base probe so deployment can assign role-specific checks.

Run `tests/docker/smoke.sh` for build, CLI, permissions, PostgreSQL, Valkey, HTTP isolation, proxy-header, log-redaction, and SIGTERM validation. It uses temporary `postgres:16.10-bookworm` and `valkey/valkey:9.1.1-trixie` containers on an isolated network, without published service ports or persistent test volumes.

## Boundaries

This repository supplies only the API image; it does not define frontend delivery, persistent services, edge proxy and public TLS, image registry and publishing, security monitoring, backup and restore, updates, or customer provisioning. Operators must provide and validate the components their deployment requires. Nginx, Apache, PHP-FPM, Kubernetes, and hosting-panel stacks are not reference deployments.
