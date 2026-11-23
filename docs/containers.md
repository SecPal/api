<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# API container

PHP 8.4-FPM/CLI and Nginx form the API runtime pair. The build definitions are
`docker/php-fpm/Containerfile` and `docker/nginx/Containerfile`, with a shared
`.containerignore`.

## Runtime architecture

The PHP image uses the official PHP 8.4 FPM/CLI Alpine 3.24 base, full ICU
data, the existing extensions, OPcache configuration and hash-locked
OpenTimestamps dependencies. It contains the application and the CLI used by queues, the activity hash chain,
scheduling and migrations. Mount persistent private storage at
`/app/storage/app/private` for roles that access uploads.
Nginx uses the official Alpine 3.24 slim base and contains only public
application files, HTTP configuration and the license. Its Containerfile also
pins the patched Alpine OpenSSL runtime libraries. Both bases and Composer
are pinned to immutable multi-platform digests in their Containerfiles.

Both processes run as UID/GID 10001 with read-only root filesystems, dropped
capabilities, no-new-privileges and explicit writable paths. Share only a
dedicated directory at `/run/secpal-fpm`, mode 0700, using the same rootless
keep-id mapping for both containers. FPM creates `api.sock` with mode 0660;
Nginx mounts that directory read-only. On SELinux hosts this shared socket
mount uses the narrow shared `:z` label. Do not disable container labeling.
There is no published FastCGI TCP listener, host networking or extra daemon.

Nginx listens on 8080, serves only `/app/public` and sends application requests
to the fixed Laravel front controller. Arbitrary PHP files, dotfiles, license
sidecars and the private FPM ping endpoint cannot be executed or exposed.
Application health endpoints run through Laravel: Nginx alone is insufficient
for liveness. Access logs omit query strings and credentials; request-specific
Nginx error logs are disabled to prevent raw request lines from leaking tokens.
Lifecycle errors remain on stderr.

FPM retains `clear_env=yes`. Its allowlist contains only non-secret deployment
settings, including the explicit `TRUSTED_PROXIES` allowlist (empty by
default). The existing production secret bootstrap is mounted externally using
`auto_prepend_file`, as qualified at
`SecPal/deployment@bf2282e3e112ac003360ecb3dff390eafe282dbd`. Only PHP receives
application secrets. The runtime uses PostgreSQL 18 with
`verify-full` TLS and SCRAM authentication. No startup command generates secrets or migrates data.

FPM and Nginx drain HTTP requests with SIGQUIT. CLI consumers of the same PHP
image must select their existing SIGTERM lifecycle and role-specific health
probe. The FPM image probe speaks FastCGI to its private ping path; the HTTP
probe checks Laravel liveness; Nginx uses BusyBox wget/timeout. Deployment supplies
the role-specific Podman/Quadlet health commands explicitly for OCI images. Consumers must preserve private storage,
bootstrap-cache and temporary-directory ownership and their existing worker
stop budgets.

### SQLite package provenance

Until Alpine 3.24 ships the upstream repair, the PHP-FPM build uses the
versioned `docker/sqlite/APKBUILD` to package unmodified SQLite 3.54.0.
Both source archives are SHA-512 pinned; the amalgamation additionally matches
SQLite.org's published SHA3-256. The SDK dependency pins are in
`docker/sqlite/build.sh`;
only Alpine 3.24 SDK packages are used.
No edge repository is added. The package remains `sqlite-libs`, with origin
`sqlite`, the genuine upstream version and its regular APK file ownership.

Two clean builds in one pinned SDK must produce byte-identical APKs. Standard
APK signatures are verified with the disposable SDK's public key; only that
key is supplied to the local install command. No build private key or extra
system trust key enters the runtime. Independent runs may differ in their
ephemeral signing envelope; bit-identical OCI images are not claimed.

The builder checks the existing `libsqlite3.so.0` SONAME and preservation of
all old public SQLite exports. `/usr/share/secpal/sqlite-build.json` records
source, recipe, package and installed-library hashes, source ID, build-package
versions, architecture and repeat-build evidence. Native smoke tests check
APK ownership, actual loaded library/source identity, PHP SQLite3/PDO, Python
SQLite/FTS5/session support and the existing OpenTimestamps contract.

This is a SecPal-built package, not an Alpine vendor security package.
It fixes the three library CVEs without filtering scanner output.
The separate SQLite CLI `.recover` CPE finding remains visible until its
component scope is correctly classified by the trusted scanner implementation.

## Native builds and PR qualification

The Container Image workflow runs rootless Buildah and Podman separately on
native AMD64 and ARM64 runners. It rejects enabled foreign-architecture
emulation. Each run of `scripts/build-container.sh ARCH OUTPUT` builds both
components and executes their shared HTTP, lifecycle and PostgreSQL smoke
against those exact images. PR code receives read-only repository and Actions
permissions.

Before building, the publisher verifies the exact clean Git commit and creates
a sealed snapshot through the accepted organization snapshot helper. No ignored
or untracked runner files enter the build context. Each external image uses a
literal tag-and-index-digest `FROM` reference. The publisher verifies the
index and native platform manifest, then binds that reference to the verified
local child in Buildah with `--pull=never`. Composer cache
mounts are build caches; they are not additional source materials or runtime
volumes. Distribution package resolutions are recorded by Syft; normalized
source timestamps do not imply bit-for-bit reproducibility.

Buildah exports OCI layouts with one authoritative Syft SPDX inventory per
runtime manifest. A local Trivy input uses its supported
OCI-directory `repository@sha256:manifest` selector. The repository-named
directory is a local content-addressed transport, not a registry publication.
The existing native observer verifies the manifest, configuration and every
layer before scanning; `local-input.json` records that distinction. Raw Trivy
results and the central scanner adapters/policy are unchanged.

For PR qualification and the manual publisher, the policy engine stays at its
accepted immutable pin.
Reviewed VEX data is read separately from protected `SecPal/.github` main;
the resolved checkout commit is retained as `reviewed-vex-checkout.json`.
The existing trusted adapter verifies each committed manifest and its ancestor
document before the unchanged policy uses it. Successful native matrix jobs
retain their producing attempts, so retrying only security qualification uses
the same source-bound images rather than rebuilding different digests. Each
native record and smoke must still match that exact producing attempt, platform,
source and runtime pair; a future attempt or changed subject cannot inherit VEX.

After scanning, the same publisher's `qualify-unpublished-pair` command
re-observes all four native layouts, SBOM and raw scanner bindings, fresh
policy evidence and successful smoke records naming both exact companions.
It retains `unpublished-runtime-pair.json` with `qualification: SUCCEEDED`,
source/tree, manifest/config digests, candidate index digests and a shared pair
identity. The original scanner records and findings stay bound to each
platform. Fresh `CLEAN`, `ACTIONABLE` and `REVIEW_REQUIRED` results can qualify
when the evidence is healthy and complete, including during requalification
of existing images. The PR workflow builds and qualifies current source when
the authenticated publisher history has no compatible baseline. Missing,
substituted, stale, unhealthy or mismatched-run qualification evidence fails
closed. Registry admission and attestation remain in the protected publication
workflow.

## Required OCI PR admission

The existing `PEST Tests` required check depends on the complete Container Image
reusable workflow for every PR to main, including documentation and workflow
changes. The native workflow is called once through PHP CI; it has no separate
PR trigger or path classifier. GitHub owns matrix and job dependency evaluation.

The PHP suite and native qualification run independently. The terminal
`PEST Tests` job always reports their results and requires both to be `success`
on PRs. A failed, missing, skipped or cancelled result cannot become a successful
skipped required check. On main pushes, the PHP suite still runs normally while
the existing publisher owns native release qualification.

## Digest-only registry publication

The protected `Publish Container` workflow runs by manual dispatch on main
with separate release authorization. The official package is
`ghcr.io/secpal/api`. The publisher writes both components' manifests and
two-platform OCI indices by digest. Each Syft SPDX inventory and its
association are retained together by digest in the same package. Grype
consumes the verified Syft bytes and Trivy scans each remote manifest
independently.

Both components must pass current, healthy CLEAN artifact policy on both
platforms and fresh CLEAN source-security admission. A read-only job scans
all four local OCI layouts and requires current CLEAN results before the
registry writer can start. The writer replays the run-bound evidence against
the exact layouts and accepted VEX data before registry authentication.
The same workflow then
verifies remote index, manifest and config bytes, attests both indices, all
four platform manifests and their Syft artifacts, and runs the smoke test
against the exact companion digests on both native runners.

The two ADR-021 build-evidence documents bind the exact source, Buildah,
OCI subjects, authoritative SBOMs, scanner results and native smoke.
`runtime-pair.json` binds both OCI index digests, the four native manifests
and configs, and hashes of both evidence documents. The publisher retains
all three documents, source-security evidence and their GitHub file-attestation
bundle in one immutable OCI artifact. Only after registry read-back,
attestation verification and final revalidation does it create the
run-scoped `runtime-pair-COMMIT-RUN-ATTEMPT` discovery tag.

Deployment verifies the attested pair artifact and uses both
`ghcr.io/secpal/api@sha256:<index-digest>` references together. Discovery tags
are mutable pointers. See the [API image consumption contract][consumption] for
the accepted release and historical recovery identities, and
[SecPal/deployment][deployment] for host, secret, edge and database setup.

[consumption]: https://github.com/SecPal/deployment/blob/main/docs/api-image-consumption.md
[deployment]: https://github.com/SecPal/deployment

### Read-only evidence refresh

The existing scan job can refresh scans of an original, attested candidate
without rebuilding it. It downloads the original prepared subjects, verifies
the attestations and Syft associations, and refreshes source and artifact
security evidence for the same commit. A separately authorized rerun of only
the acceptance job can accept that same candidate once all evidence is current
and CLEAN.

Acceptance authenticates the original successful native build and runtime
attempt and the latest successful scan attempt. It rejects absent, expired,
ambiguous or failed artifacts. Final build evidence binds the original
build, smoke and OCI subjects, the refreshed scan evidence and the acceptance
run. The discovery tag retains the original candidate attempt. Re-running all
jobs creates ambiguous prepared attempts and fails closed.

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

## Run-tag retention

GHCR does not provide a proven tag-only deletion operation for this package.
GitHub's supported deletion API removes a package version, while OCI manifest
deletion targets registry content; neither operation has been proven to remove
only a run-scoped pointer while preserving the shared index digest and all
associated evidence. SecPal therefore retains every run-scoped discovery tag.

No publisher job or scheduled workflow has cleanup authority. The publisher
uses its `packages: write` grants only to publish the new pair and associated evidence, and the workflow policy rejects registry deletion
commands. Cleanup is outside the publication critical path.

Distinct publication runs do not share discovery tags. Failed acceptance can
retain untagged candidates and partial immutable evidence without accepting a
release. If interruption occurs after pointer creation, the pointer may exist
before successful read-back is recorded. A retry verifies an existing pointer
against the exact original pair artifact and never overwrites it. Unknown registry
errors fail closed rather than being treated as absence. Retention performs no
registry cleanup.

Tag cleanup remains unsupported unless an isolated disposable package proves
that a tag-only operation preserves digest pulls, platform manifests, the
authoritative SBOM, build evidence, and GitHub Artifact Attestations across
success, interruption, repetition, and concurrent publication. Missing,
ambiguous, or changed provider semantics fail closed by retaining the tag. This
policy protects the builder-independent evidence model and its immutable subjects.

## Released-artifact re-evaluation

`.github/workflows/released-artifact-security.yml` re-evaluates the reviewed
accepted PHP-FPM/Nginx runtime pair daily: FPM at 03:17 UTC (AMD64) and
05:17 UTC (ARM64), Nginx at 03:37 UTC (AMD64) and 05:37 UTC (ARM64).
Manual dispatch selects a component and platform, optionally asserting that
component's exact index digest. The four native subjects retain separate index,
SBOM and security identities within one attested release pair. Each invocation
has its own run because shared evidence names are run-scoped. Other digests,
swapped component indices and incomplete publisher candidates are rejected
before scanning.

The workflow's `MONITORED_RELEASE` tuple is derived from accepted, attested
build evidence. Updating it requires a reviewed released subject; the workflow
does not automatically enroll newer candidates or discover arbitrary registry
tags. Publisher repository/workflow identities are fixed in the caller.

The immutable shared workflow owns publisher-attestation and authoritative
Syft association verification, fresh Grype/Trivy evidence, unchanged policy
evaluation and deterministic finding-issue triage. Registry access is read-only;
only its isolated validated triage job has issue-write permission. Raw and
normalized evidence is retained for 14 days. Failed or stale evidence remains
fail-closed.

A successful monitoring execution can report ACTIONABLE or REVIEW_REQUIRED:
operational monitoring success is not CLEAN or release permission. Original
findings and historical acceptance are preserved. Monitoring observes only the
reviewed released subjects.
