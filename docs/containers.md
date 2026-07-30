<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# API container

The SecPal API has one production image for HTTP, queue, scheduler, and administrative roles. Docker is the reference hosting path, while Laravel remains deployment-neutral and can still run outside containers.

## Image architecture

The runtime is based on `dunglas/frankenphp:1.12.6-php8.4.23-bookworm` at multi-architecture digest `sha256:79b347211bfec90d6a1373c4956a7d3832c8248a2ff2d76bd0b677f37284d32f`. It provides FrankenPHP 1.12.6, PHP 8.4.23, and Debian 12 (Bookworm), with verified `amd64` and `arm64` manifests. Debian is the upstream-recommended variant and avoids Alpine/musl compatibility differences.

FrankenPHP serves Laravel in Classic Mode on internal HTTP port `8080`. Worker Mode and Laravel Octane are not enabled. Automatic HTTPS, ACME, and the Caddy admin API are disabled. Only `/app/public` is the web root.

The final process runs as `secpal` (UID/GID `10001`) without additional Linux capabilities. Application source is root-owned; `storage` and `bootstrap/cache` are writable by `secpal`. Volume owners must be compatible with UID/GID `10001`.

The application extensions include `bcmath`, `curl`, `gettext`, `intl`, `mbstring`, `opcache`, `pcntl`, `pdo_pgsql`, `pgsql`, `sodium`, XML support, and `zip`. Native PhpRedis 6.3.0 is installed. Python 3 and `opentimestamps-client` 0.7.2 support the existing `opentimestamp` queue. No Node.js, Redis server, or Valkey server is included.

## Build and HTTP start

Build from the repository root without an `.env`, `APP_KEY`, KEK, database, or Redis-compatible server:

```bash
docker build -t secpal-api:test .
```

The default command is:

```text
frankenphp run --config /etc/frankenphp/Caddyfile
```

A local HTTP instance can be started with runtime configuration:

```bash
docker run --rm -p 8080:8080 -e APP_KEY -e DB_CONNECTION=pgsql -e DB_HOST -e DB_DATABASE -e DB_USERNAME -e DB_PASSWORD secpal-api:test
```

The image does not generate secrets or run migrations at startup.

## Container roles

Override the default command to use the same image for each non-HTTP role:

```bash
# Exactly one instance per installation; enforced by the future deployment stack.
php artisan queue:work --queue=activity-hash-chain --sleep=3 --tries=3

# Independently scalable where application workload permits.
php artisan queue:work --queue=merkle,opentimestamp,default --sleep=3 --tries=3

# Exactly one instance per installation; enforced by the future deployment stack.
php artisan schedule:work

# Examples of explicit administrative operations.
php artisan about
php artisan schedule:list
php artisan migrate --force
```

Migrations are always an explicit deployment operation after a backup or restore point; they are never part of the image entrypoint or healthcheck.

## PostgreSQL and Valkey

PostgreSQL remains SecPal's persistent primary database and is not part of the API image. All connection values remain configurable through Laravel's normal `DB_*` environment contract.

PhpRedis communicates over RESP with Redis-compatible servers. Valkey is the preferred future reference backend but remains optional. Configuration continues to use `REDIS_CLIENT=phpredis` and the existing `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_DB`, and `REDIS_CACHE_DB` variables; no `VALKEY_*` contract is added. Queue, cache, and session defaults remain database-backed until the deployment milestone.

## KEK and other secrets

The KEK is an external raw 32-byte secret. Set `KEK_PATH` to its runtime path, mount it read-only where practical, make it readable by UID `10001`, and keep its mode exactly `0600`. The application default remains `storage/app/keys/kek.key`; `/run/secrets/secpal-kek` is an optional container path, not a required convention.

Never add the KEK, `APP_KEY`, `.env`, database or Redis/Valkey credentials, mail credentials, tokens, private certificates, or API keys to the image. Supply secrets externally through environment variables or configurable files. The image never creates a KEK or `APP_KEY`.

## Health and validation

`GET /health/live` reports process liveness without PostgreSQL or Valkey. `GET /health/ready` checks the configured database, tenant key, readable KEK, scheduler heartbeat, and relevant queue-worker heartbeats. The reusable `secpal-http-live` script probes liveness. The shared image defines no Docker `HEALTHCHECK`; the deployment repository will attach role-specific checks.

Run the complete build, CLI, permissions, PostgreSQL, Valkey, HTTP-routing,
content-isolation, and SIGTERM smoke suite with:

```bash
tests/docker/smoke.sh
```

The suite uses temporary `postgres:16.10-bookworm` and `valkey/valkey:9.1.1-trixie` containers on an isolated network without published service ports or persistent volumes.

## Milestone boundaries

This milestone does not provide a frontend image, complete Compose stack, deployment repository, persistent PostgreSQL or Valkey service, edge proxy, public TLS, CrowdSec, Falco, GHCR or multi-architecture publishing, backup/restore automation, updates, or customer provisioning. Those belong to later deployment work. Individual Nginx, Apache, PHP-FPM, Kubernetes, and hosting-panel stacks are not maintained as reference deployments.
