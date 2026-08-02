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

## Immutable registry publishing

Official SecPal API container images are published exclusively as
`ghcr.io/secpal/api`. The Docker Hub namespace `secpal`, including shortened
references such as `secpal/api`, is not controlled or endorsed by SecPal.
There are no registry fallbacks, and official pull or inspect commands must not
use shortened image names. Local test tags use the non-namespace name
`secpal-api`, such as `secpal-api:test`.

After this publishing workflow reaches `main`, each push to `main` will build
and publish `ghcr.io/secpal/api` as an OCI image index for `linux/amd64` and
`linux/arm64`. Phase C.1 publishes exactly one discovery tag per commit:
`sha-<full-40-character-commit-SHA>`. It does not publish `latest`, a branch
tag, a SemVer tag, or a release tag.

The canonical consumption reference is always the returned index digest:

```text
ghcr.io/secpal/api@sha256:<manifest-digest>
```

The SHA tag is only a discovery alias for finding that digest. Production and
deployment consumers must resolve the tag once and pin the canonical GHCR
digest rather than treating any tag as the deployment identity. Publishing
does not create a deployment contract or perform a deployment.

The publisher creates a SHA tag only after a registry lookup has confirmed
that it is absent. A workflow rerun reuses the existing digest after checking
the exact runtime platform set plus the source and revision labels; it never
pushes the same SHA tag again. Only an authenticated `404` authorizes the first
publish. Authentication, network, or other registry lookup failures stop the
job instead of being treated as permission to overwrite the tag. This makes
post-push attestation or verification failures safely retryable without moving
the discovery alias.

Each runtime image config records the source repository, full revision,
creation timestamp, title, description, and the repository's effective SPDX
license expression. The source label is
`https://github.com/SecPal/api`, and the license label is
`AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution`.

The published index has four distinct supply-chain concepts:

- the `linux/amd64` and `linux/arm64` manifests are executable runtime images;
- the BuildKit SPDX SBOM describes packages in each runtime image;
- the BuildKit SLSA provenance uses `mode=max` and records the build inputs and
  invocation for each runtime image;
- the GitHub Artifact Attestation signs the untagged image name plus the
  published index digest through workload OIDC, tying it to `SecPal/api`, this
  workflow, and the source commit.

BuildKit stores the SBOM and provenance as attestation manifests associated
with the runtime manifests. Their `unknown/unknown` platform descriptors are
attachments, not extra runtime platforms. Remote verification excludes those
attachments when asserting the exact two-platform runtime set, then inspects
the SBOM and provenance separately.

Before the first successful post-merge publish, no registry availability is
implied. GHCR packages are private by default. After that publish succeeds, a
repository administrator must verify the package link and change the package
visibility to public once. Anonymous pulls are supported only after that
separate administrative step. No personal access token, long-lived signing
key, or Cosign key secret is part of this contract.

While the package is private, authenticate to GHCR before verification. After
it is public, the image and its BuildKit attestations can be inspected
anonymously by digest. GitHub Artifact Attestation verification uses the
GitHub attestation API and may still require GitHub CLI authentication:

```bash
docker buildx imagetools inspect \
  ghcr.io/secpal/api@sha256:<manifest-digest>
gh attestation verify \
  oci://ghcr.io/secpal/api@sha256:<manifest-digest> \
  --repo SecPal/api
docker pull ghcr.io/secpal/api@sha256:<manifest-digest>
```

The publishing workflow additionally proves that the SHA tag resolves to the
reported digest, the runtime platform set is exact, source and revision labels
match, both BuildKit attestations are readable, the GitHub attestation verifies
for `SecPal/api`, and the existing container smoke contract passes against an
image pulled only by digest.

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

Run `tests/docker/smoke.sh` for build, CLI, permissions, PostgreSQL, Valkey, HTTP isolation, proxy-header, log-redaction, and SIGTERM validation. It uses temporary `postgres:16.10-bookworm` and `valkey/valkey:9.1.1-trixie` containers on an isolated network, without published service ports or persistent test volumes. The publishing verifier first pulls the immutable digest and sets `SKIP_BUILD=1` so the same checks exercise the remote artifact without rebuilding it.

## Boundaries

This repository supplies and publishes only the API image; it does not define frontend delivery, persistent services, edge proxy and public TLS, deployment, security monitoring, backup and restore, updates, or customer provisioning. Operators must provide and validate the components their deployment requires. Nginx, Apache, PHP-FPM, Kubernetes, and hosting-panel stacks are not reference deployments.
