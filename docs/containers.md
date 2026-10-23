<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# API container

SecPal uses one production API image for HTTP, queues, scheduling, and administration. The reference host uses rootless Podman/Quadlet; Laravel remains deployment-neutral.

## Architecture

The authoritative base references are the exact version tags and multi-architecture SHA-256 digests in `Containerfile`. The runtime uses official FrankenPHP with PHP 8.4 on Debian 12 Bookworm; the smoke test verifies the actual binaries against that input and requires at least PHP 8.4.27 for the PHP2Shell repair. The native build verifies the `amd64`/`arm64` platform identities. Composer is independently pinned for the build stage. The Debian variant avoids Alpine/musl compatibility differences.

The security patch does not imply artifact-policy CLEAN. Fresh per-platform scans and any separately authorized exact-artifact admission remain required before release acceptance.

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

Each new main-push run builds its own image. Candidate subjects are immutable
and source-bound. An explicitly authorized retry of only the failed acceptance
job reuses the same run's original complete candidate attempt; it does not build
again. The original build and smoke identities remain in build evidence, while
the later acceptance execution is recorded separately below the publisher
extension. The discovery tag retains the original candidate attempt.

Do not re-run all jobs as a continuation. Multiple prepared attempts are
ambiguous and fail closed; neither a moving tag nor an unverified caller-supplied
digest selects a candidate.

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

Distinct main-push runs do not share discovery tags. Failed acceptance can
retain untagged candidates and partial immutable evidence without accepting a
release. If interruption occurs after pointer creation, the pointer may exist
before successful read-back is recorded. A retry verifies an existing pointer
against the exact original index and never overwrites it. Unknown registry
errors fail closed rather than being treated as absence. Retention performs no
registry cleanup.

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
evidence blocks acceptance. Review-required or actionable results remain blocked
unless an explicit current exact-artifact operator risk decision
described below is valid.

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

## Source evidence and artifact risk acceptance

The publisher consumes the accepted v4 source-security evidence and schema from
one immutable shared revision. Source security must be SUCCEEDED, CLEAN and
FRESH for the exact accepted commit. Its database deadline remains strict;
expiry is never extended by an artifact decision.

Artifact scanning retains both architectures' authoritative Syft associations,
original Grype/Trivy output, metadata, evaluation inputs and unchanged v1 policy
results. Ordinary acceptance requires current healthy CLEAN results on both
platforms. Native runtime tests, source binding, OCI byte verification and all
five candidate attestations remain mandatory for either admission path.

If findings remain, acceptance retains `artifact-security-evidence.json`
and fails until an explicit administrator decision exists in issue #1445.
The file is the publisher's existing verified source/index/platform/config/SPDX,
raw scanner, policy and runtime evidence identity. Its canonical SHA-256 is the
decision's `evidence_sha256`. Original findings remain available in the scan
artifacts; no archive upload to a particular reviewer is required.

Use the centrally maintained native comment format in
[the artifact policy](https://github.com/SecPal/.github/blob/main/docs/vulnerability-triage-policy.md#explicit-artifact-risk-acceptance).
The decision explicitly names the source commit, index, both platform digests,
current evidence digest, residual risks, rationale, mitigations, rollback
strategy and bounded UTC expiry. Its actual GitHub author must currently
administer SecPal/api. No bot assessment, second approval or decision PR is
required. This documentation and implementation authorization are not risk
acceptance for any artifact.

Set the repository variable
`ARTIFACT_RISK_ACCEPTANCE_COMMENT_ID` to the exact native comment ID after
the operator publishes the decision. This value is only a locator, not another
approval. Leave it unset when no decision is active. The publisher fetches only
that comment, with no inventory search or fallback to earlier approvals.
Revoke by editing or deleting the referenced acceptance itself, including to\nstop an in-flight acceptance.

The referenced decision is read again immediately before discovery. Missing, edited, deleted, revoked, expired, mismatched or non-admin
decisions deny acceptance. A successful explicit decision produces a separate
`ADMITTED_WITH_KNOWN_FINDINGS` receipt, never CLEAN. The receipt and complete
unchanged policy results are retained as `artifact-risk-acceptance.json`
with the immutable build-evidence artifact.

### Read-only evidence refresh

Do not rebuild an unchanged image solely to refresh security evidence.
The existing scan job downloads the original prepared OCI identities, verifies
their attestations and Syft associations, and scans those exact manifests again
read-only. It also refreshes source security for the same accepted commit.
Re-running only this job produces a new run-attempt scan artifact, without
invoking build, preparation, attestation generation or registry publication.

The acceptance job authenticates the original successful native build/runtime
attempt and the latest successful scan attempt. It rejects absent, expired,
ambiguous or failed artifacts. Its final build evidence preserves the original
source, native runner, workflow and OCI identities; current source/scanner
evidence is separately bound. A new scan changes the evidence identity and
requires a new explicit operator decision if it remains non-CLEAN.

Once the current evidence and any required decision exist, an independently
authorized rerun of only the existing acceptance job may finish the same
candidate. No runtime or risk approval is granted by installing this
implementation. Do not re-run all jobs, transfer approvals, rewrite scanner
records or manually create discovery. Production deployments remain separate.

Final build evidence and discovery remain downstream of admission.
The final stage verifies the document and OCI evidence attestations, rechecks
the five candidate attestations and current admission, and resolves the unique
discovery pointer to the exact immutable index. An interrupted or rejected
acceptance cannot authorize discovery.
