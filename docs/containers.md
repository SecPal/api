<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# API container

SecPal uses one production API image for HTTP, queues, scheduling, and administration. The reference host uses rootless Podman/Quadlet; Laravel remains deployment-neutral.

## Architecture

The pinned base is `dunglas/frankenphp:1.12.6-php8.4.23-bookworm` at multi-architecture digest `sha256:79b347211bfec90d6a1373c4956a7d3832c8248a2ff2d76bd0b677f37284d32f`. It provides FrankenPHP 1.12.6, PHP 8.4.23, Debian 12, and verified `amd64`/`arm64` manifests. The Composer 2.10.2 build stage is also pinned to its multi-architecture digest. The Debian variant avoids Alpine/musl compatibility differences.

FrankenPHP serves only `/app/public` in Classic Mode on HTTP port `8080`. Worker Mode, Octane, automatic HTTPS, ACME, and Caddy's admin API are disabled. The base image's OCI metadata still declares ports 80, 443, and 2019, but no process listens there. The `secpal` user (UID/GID `10001`) runs without extra capabilities; root owns source, while `storage` and `bootstrap/cache` are writable by that UID/GID.

Extensions are `bcmath`, `curl`, `gettext`, `intl`, `mbstring`, `opcache`, `pcntl`, `pdo_pgsql`, `pgsql`, `sodium`, XML, and `zip`. Python 3 and the hash-locked `opentimestamps` 0.4.5 core library support the existing queue without the OpenTimestamps CLI or GitPython. Node.js and Redis-compatible clients or servers are absent.

## Build and HTTP

Build without `.env`, `APP_KEY`, KEK, or PostgreSQL:

```bash
buildah build --format oci --file Containerfile --tag secpal-api:test .
podman run --rm -p 8080:8080 -v secpal-api-storage:/app/storage/app/private -v /deployment/postgresql-ca.crt:/run/secrets/postgresql-ca.crt:ro -e APP_KEY -e DB_CONNECTION=pgsql -e DB_HOST -e DB_DATABASE -e DB_USERNAME -e DB_PASSWORD -e DB_SSLMODE=verify-full -e DB_SSLROOTCERT=/run/secrets/postgresql-ca.crt secpal-api:test
```

The default command is `frankenphp run --config /etc/frankenphp/Caddyfile`. No startup step generates secrets or runs migrations. Production must mount persistent storage at `/app/storage/app/private` and share it with roles that access uploads; otherwise database metadata can outlive container-local blobs.

Set `TRUSTED_PROXIES` to a comma-separated allowlist of proxy IP addresses or CIDRs only when deployed behind trusted proxies. It is empty by default.

## Digest-only registry publishing

Official SecPal API container images are published exclusively as
`ghcr.io/secpal/api`. The Docker Hub namespace `secpal`, including shortened
references such as `secpal/api`, is not controlled or endorsed by SecPal.
There are no registry fallbacks, and official pull or inspect commands must not
use shortened image names. Local test tags use the non-namespace name
`secpal-api`, such as `secpal-api:test`.

After this publishing workflow reaches `main`, every run builds and publishes
`ghcr.io/secpal/api` as an OCI image index for `linux/amd64` and `linux/arm64`.
Each run writes exactly one run-scoped discovery tag:

```text
ghcr.io/secpal/api:build-<full-SHA>-<run-ID>-<run-attempt>
```

SecPal does not reuse that run-specific tag for another run. This workflow
rule does not imply registry-enforced tag immutability. The publisher does not
write `latest`, a branch tag, a SemVer tag, a release tag, or a stable
full-commit-SHA tag.

The publisher's static container policy suite is isolated from the Laravel
database test case and runs without PostgreSQL. Pull-request validation runs
the same database-free policy command used by the post-merge publisher before
any registry write is permitted.

### Pre-build repository security

Quality Checks on pull requests and `main` invoke the same API-local reusable
workflow as the mandatory `repository-security` publisher predecessor. The
publisher requires both image validation and repository security acceptance.
Each invocation checks out `${{ github.sha }}` with credentials disabled and
calls the input-free organization action at the full commit SHA pinned in
`.github/workflows/reusable-repository-security.yml` with only `contents: read`.

Only `CLEAN` satisfies API acceptance. `ACTIONABLE`, `REVIEW_REQUIRED`,
`UNKNOWN_STALE`, missing and unknown states block publication. Failed or skipped
scans cannot bypass the job dependency. The organization action owns scanner,
database, exact commit and policy identity, freshness, redaction, and bounded
evidence; API callers provide no ignores, overrides or exceptions. Scanning has
no source, dependency-fix, image-publish or deployment authority.

This repository/source evidence is distinct from exact released OCI artifact
Grype/Trivy evidence owned by `api#1383` and `api#1448`. It does not establish the
security of the built image or replace any artifact-level acceptance.

The database-free `tests/Policy/RepositorySecurityWorkflowTest.php` proves
workflow identity, least privilege, publisher dependencies and fail-closed API
acceptance. On Linux x64, run the real scanner integration with a clean local
organization checkout at the accepted revision:

```bash
php tests/repository-security/replay.php /path/to/SecPal/.github
```

The workflow is the sole source of the scanner revision. The replay reads its
pin and rejects mutable or unexpected action references and mismatched or dirty
organization checkouts before scanning, then executes the reviewed action itself
against one temporary exact Git
commit containing a vulnerable Composer development dependency, an insecure PHP
Containerfile and a generated synthetic `.env` secret. It asserts fresh API-bound
evidence, all three finding classes, redaction across retained action output and
evidence, and API rejection of `ACTIONABLE`, then deletes the fixtures. It never
installs fixture dependencies, copies scanner implementation or publishes an
image. Scanner-health and invalid-checkout failure semantics remain owned and
tested by the pinned organization action.

The canonical consumption reference is always the returned index digest:

```text
Official canonical reference:
ghcr.io/secpal/api@sha256:<digest>

Non-canonical discovery only:
ghcr.io/secpal/api:build-<sha>-<run-id>-<attempt>

Not operated or endorsed by SecPal:
secpal/api
docker.io/secpal/api
```

Registry tags are mutable, non-authoritative pointers. Production and
deployment configuration must never accept a tag as an image identity or trust
anchor. The run-scoped tag exists only to discover the index produced by that
workflow run; the logged digest remains canonical even if the tag later moves.
Publishing does not create a deployment contract or perform a deployment.

Every run builds its own image. A rerun receives a new run or attempt identity,
publishes a new run-scoped tag, verifies its newly built digest, and creates a
new GitHub Artifact Attestation. The publisher performs no registry lookup to
reuse a previous image, never adopts a registry-sourced digest, and never skips
the build because another tag exists.

## Run-tag retention

GHCR does not provide a proven tag-only deletion operation for this package.
GitHub's supported deletion API removes a package version, while OCI manifest
deletion targets registry content; neither operation has been proven to remove
only a run-scoped pointer while preserving the shared index digest and all
associated evidence. SecPal therefore retains every run-scoped discovery tag.

No publisher job or scheduled workflow has cleanup authority. The publisher
uses its `packages: write` grants only to publish the newly built image and its
GitHub Artifact Attestation, and the workflow policy rejects registry deletion
commands. Cleanup is outside the publication critical path.

Interrupted, repeated, and concurrent runs do not share a discovery tag. A run
that stops after tag creation may leave that pointer in place; a retry uses a distinct run
or attempt identity and verifies a new digest independently. Retention is
idempotent and has no race with another run because it performs no registry
mutation.

Tag cleanup remains unsupported unless an isolated disposable package proves
that a tag-only operation preserves digest pulls, platform manifests, the
authoritative SBOM, build evidence, and GitHub Artifact Attestations across
success, interruption, repetition, and concurrent publication. Missing,
ambiguous, or changed provider semantics fail closed by retaining the tag. This
policy protects the builder-independent evidence model and its immutable subjects.

## Native build and supply-chain acceptance

`Containerfile` and `.containerignore` are the only active build definition.
The same read-only workflow builds AMD64 on an AMD64 runner and ARM64 on an
ARM64 runner using rootless Buildah. It rejects enabled foreign-architecture
emulation. PR code receives neither package-write nor GitHub OIDC authority.

Before building, the publisher verifies the exact clean Git commit and creates
a sealed snapshot through the accepted organization snapshot helper. No ignored
or untracked runner files enter the build context. Each pinned multi-platform
base is resolved and verified to its native platform manifest. Composer cache
mounts are build caches; they are not additional source materials or runtime
volumes. Distribution package resolutions are recorded by Syft; normalized
source timestamps do not imply bit-for-bit reproducibility.

The native jobs export OCI layouts and one pinned Syft SPDX inventory for each
runtime manifest. The publisher independently hashes the manifests/configs,
checks the exact two-platform set, non-root user and OCI source/base labels,
and assembles one OCI index. It uploads candidate subjects by digest into
`ghcr.io/secpal/api`; no discovery tag exists at this stage.

Each SPDX inventory and its subject association are retained together in an
immutable OCI artifact in that same package. Grype consumes the verified Syft
bytes. Trivy independently scans each immutable remote runtime manifest.
The accepted organization adapters and vulnerability policy own database
freshness, actionability and reviewed VEX admission. A database failure, stale
evidence, review-required or actionable result blocks acceptance.

GitHub OIDC attestations bind the index, both platforms and both SPDX artifacts
to this repository, workflow and exact source SHA. Both native runners then pull
their exact platform digest and execute the complete runtime smoke contract,
including the PHP executable's ELF architecture. A host architecture string or
manifest label alone is insufficient.

The ADR-021 build-evidence document binds source/context hashes, Buildah/tool
identity, platform/config/index digests, base manifests, SPDX subjects, scans,
native smoke and run/attempt identity. It and the exact source-scan result are
retained with a GitHub file-attestation bundle in an immutable OCI artifact.
The final job independently verifies the remote artifact, attestations and
index bytes before creating the sole run-scoped discovery tag. It reads the
tag back afterward. Failed or interrupted runs may leave untagged candidate
subjects; they never receive an accepted discovery pointer.

All runtime configs record source, full revision, source-epoch creation time,
title, description, effective SPDX license, reviewed base name and resolved
base manifest digest. OCI image configs omit Docker-specific healthcheck
extensions; deployment assigns role-specific health probes.

Before the first successful Buildah publisher run, operational publisher
acceptance remains pending. Source acceptance alone does not establish a
working publisher, public package visibility or anonymous pulls. No personal
access token, long-lived signing key or registry-deletion authority is added.

Inspect accepted subjects by their independently verified immutable digest:

```bash
skopeo inspect --raw docker://ghcr.io/secpal/api@sha256:<index-digest>
gh attestation verify \
  oci://ghcr.io/secpal/api@sha256:<index-digest> \
  --bundle-from-oci --repo SecPal/api
podman pull ghcr.io/secpal/api@sha256:<index-digest>
```

GHCR authentication may be needed while the package is private. Package
visibility and linkage remain separately administered; publishing deploys no
application. Recurring re-evaluation of already released artifacts belongs
to #1448, and final retired-path documentation cleanup belongs to #1446/#1453.

## Source-evidence repair and prepared admission interface

The publisher consumes the accepted v4 repository-security schema, preserving
the complete scanner/policy identity and exact source commit. The same reader
checks successful CLEAN execution and the producer's database interval
`updated_at <= observed_at < next_update` during preparation, evidence assembly
and final acceptance. Its reviewed schema is byte-identical to the schema used
by the pinned source producer.

The separate `container-publisher.py check-security-improvement` command prepares
the API #1609 integration. It rechecks the retained OCI bytes using the existing
platform verifier, source/tree/index, SPDX association, raw scanner evidence and
evaluation inputs before calling the immutable central
`admit-api-security-patch` implementation. The existing trusted-control checkout
must provide that reviewed implementation at `.context/publisher-governance`;
neither bundle nor decision may select code or policy. Decision checkout/commit
are separate reviewed data, verified against current accepted central authority.
The command always obtains a fresh central decision and retains it separately
as `security-improvement-admission.json`, preserving original scan results.
A cached document is not authority. Tests use synthetic responses for the API
seam; the real central command is also exercised to prove denial without an
active decision.

No publication stage invokes this command. Ordinary scan and build-evidence
acceptance remain CLEAN-only; a prepared ADMITTED_WITH_KNOWN_FINDINGS document
cannot authorize evidence attestation, discovery or deployment. No active
decision, risk approval or release permission is included.

Activation needs a separately authorized exact-artifact operator decision and
independent security assessment, and reviewed consumption at the existing scan,
build-evidence and final-acceptance boundaries. Actual non-CLEAN results must
remain visible in the retained and attested evidence. Run/attempt bindings and
the currently push-only workflow also require a legitimate evidence-continuation
contract; rebuilding cannot transfer approval to different subjects. These
remaining changes belong to #1445 and are not enabled by this reader repair.

## Roles

Override the default command for the non-HTTP roles:

```bash
# Independently scalable; database queue semantics and business invariants coordinate workers.
php artisan queue:work --queue=activity-hash-chain --sleep=3 --tries=3
# Independently scalable where application workload permits.
php artisan queue:work --queue=merkle,opentimestamp,default --sleep=3 --tries=3
# One process is sufficient for Single, but PostgreSQL-backed locks permit multiple schedulers.
php artisan schedule:work
# Administrative examples.
php artisan about
php artisan schedule:list
php artisan migrate --force
```

Migration is an explicit deployment operation after a backup or restore point, never an entrypoint or healthcheck action.

## PostgreSQL and secrets

PostgreSQL is the external persistent database and shared authority for sessions, durable queues, cache, and scheduler locks. Production requires `DB_SSLMODE=verify-full` and a deployment-owned trusted CA path in `DB_SSLROOTCERT`; the PostgreSQL service owns SCRAM-SHA-256 authentication policy. The supported Laravel drivers are `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, and `CACHE_STORE=database`. Channel binding is not exposed because the currently qualified Laravel PDO connector does not forward that libpq option.

The KEK is an external raw 32-byte secret. Configure `KEK_PATH`, mount the file read-only where practical, make it readable by UID `10001`, and set mode `0600`. The default is `storage/app/keys/kek.key`; `/run/secrets/secpal-kek` is optional. Never place the KEK, `APP_KEY`, `.env`, credentials, tokens, private certificates, or API keys in the image; it creates none of them.

## Health and validation

`GET /health/live` does not require PostgreSQL. `GET /health/ready` checks the configured database, tenant key, KEK readability, scheduler heartbeat, and relevant worker heartbeats. `secpal-http-live` is the reusable liveness probe. `HEALTHCHECK NONE` clears the base probe so deployment can assign role-specific checks.

Run `tests/docker/smoke.sh` for build, CLI, permissions, verified-TLS PostgreSQL 18 with SCRAM-SHA-256, HTTP isolation, proxy-header, log-redaction, and SIGTERM validation. It uses a digest-pinned PostgreSQL 18 Bookworm container on an isolated network without published service ports or persistent test volumes. This disposable container is integration evidence only; production PostgreSQL remains external. The publishing verifier resolves and pulls each immutable platform-manifest digest from the verified index and sets `SKIP_BUILD=1` so the same checks exercise each remote runtime artifact without rebuilding it.

## Boundaries

This repository supplies and publishes only the API image; it does not define frontend delivery, persistent services, edge proxy and public TLS, deployment, security monitoring, backup and restore, updates, or customer provisioning. Operators must provide and validate the components their deployment requires. Nginx, Apache, PHP-FPM, Kubernetes, and hosting-panel stacks are not reference deployments.
