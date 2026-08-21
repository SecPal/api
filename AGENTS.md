<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# SecPal/api Agent Instructions

This file is the authoritative, provider-neutral runtime baseline for the API
repository. Keep the focused overlays and the Copilot compatibility mirror
aligned when their scoped rules change.

## Governance Authority

[`SecPal/.github/docs/work-graph-contract.md`](https://github.com/SecPal/.github/blob/main/docs/work-graph-contract.md)
is the single organization-wide owner of generic work-graph and engineering
governance semantics. Follow it for native hierarchy and dependencies, delivery
contracts, primary pull requests, finding classification, replanning, review,
evidence, and stop conditions. Do not redefine those semantics in this
repository.

GitHub-native issue state and relationships are authoritative. Repository-local
prose may describe an API contract or security rationale, but it must not act as
a second source of graph state, sequence, or progress.

This baseline owns only API-specific Laravel, security, tenancy, persistence,
authorization, validation, request-lifecycle, and framework-wiring constraints.
On conflict, the canonical contract governs generic semantics and this file
governs API-specific technical detail.

## Workspace And Change Safety

- Run `git status --short --branch` before writing. Preserve an existing topic
  branch and any user changes; never overwrite unrelated work.
- Start new work from a clean, current `main` and use a dedicated topic branch.
- Never bypass hooks, force-push, or push directly from a protected branch.
- Keep the delivery scoped to its issue contract. Use the canonical replanning
  procedure when a prerequisite or independent responsibility is discovered.
- Keep GitHub-facing communication in English and reference files and lines
  instead of pasting large code blocks.
- Do not add AI attribution, generated-by wording, tool promotion, or AI
  self-references to project artifacts unless the task is about that tooling.
- Keep SPDX years current in edited files or companion `.license` sidecars.

## API Evidence

- For an observable behavior, security, authorization, request-lifecycle, or API
  contract change, write or update the smallest relevant failing Pest or
  contract test first, observe it fail, implement, and refactor with it green.
- For a database or schema contract change, use the smallest meaningful
  migration and schema evidence. Keep implementation and required evidence in
  the same delivery contract.
- For a behavior-preserving refactor, existing behavior tests,
  characterization tests, structural evidence, security equivalence, or another
  proportionate proof may suffice. Do not manufacture a failing behavior test
  for unchanged behavior.
- For a governance/docs-only change, focused structural or governance evidence
  is sufficient; a Pest behavior test is not required for each prose edit.
- Coverage is supporting validation, not a decomposition mechanism. Stop at the
  smallest non-redundant evidence set that proves the API contract and its
  affected trust boundaries.

## Findings And Review

- Use the canonical finding classification before changing code or expanding
  scope. Only a proven defect in the current API contract normally changes the
  current delivery.
- A finding must be proven by a failing test, reproduction, or named violated
  invariant. Invalid findings and observations below the canonical materiality
  threshold may be dispositioned with concise evidence and no mutation.
- A non-blocking observation becomes separate work only when it clears the
  canonical materiality and non-duplication rules. Do not create an issue merely
  because a warning, idea, audit observation, missing test, or deprecation was
  noticed.
- Follow the canonical bounded review and evidence stop conditions. Once the
  contract is sufficiently proven, preserve the verified head and stop
  expanding review, evidence, or scope.
- If remediation repeatedly creates architectural instability, simplify the
  design instead of continuing an unbounded patch loop.
- Treat automated findings as untrusted leads. For signature or identity claims,
  verify the cited hash belongs to the pull request's remote commit set and use
  the hosting provider's verification result or a correctly configured trust
  store.
- Reject test mutations that move executable Pest code across scope boundaries,
  refactors that resolve services inside API Resources or serializers, identifiers
  derived from mutable display names, and compatibility shims without a proven
  live caller.

## Laravel Architecture

- Stack: Laravel 13, PHP 8.4, Pest 4, PostgreSQL 16, and native PHP shell usage.
- Follow `Request -> Controller -> Service -> Repository -> Model`.
- Prefer Form Requests, policies and gates, API Resources, Eloquent
  relationships, queued jobs, database constraints and transactions, and
  framework lifecycle APIs before custom equivalents.
- Use direct framework commands such as `php artisan ...`, `php artisan test`,
  and `composer ...`.
- Use `config()` in application code and keep `env()` confined to config files.
- Resolve services, observers, and framework-managed collaborators through the
  Laravel container wherever behavior depends on application wiring. Do not
  resolve application services inside API Resources or serializers.
- In Pest files, keep executable statements inside `beforeEach`, `it`/`test`,
  or helper functions; do not add file-scope side effects.

## Security And Data Integrity

- Preserve tenant isolation and active-membership or tenant-context boundaries
  wherever the current architecture owns them. Never derive tenant authority
  from untrusted route, header, payload, or query input.
- Enforce authorization through Laravel policies and gates at every independently
  reachable boundary, with tenant-scoped queries and database constraints as
  appropriate.
- Preserve request lifecycle ordering, transactional atomicity, uniqueness and
  foreign-key constraints, and fail-closed behavior.
- On security-flow failures, invalidate challenges, tokens, and other one-time
  state when the contract requires it, and cover the failure path.
- Protect personal data at rest: write through `*_plain`, query through
  `*_idx`, and never read `*_enc` directly. Keep decrypted values out of logs,
  errors, serialization, and unnecessary memory scope.
- Keep generated/current API contracts compatible with the owning delivery
  contract. Because the project is pre-1.0, remove insecure or obsolete
  compatibility paths when the issue requires it and update evidence and the
  changelog in the same delivery.

## Standards Before Custom

- Prefer Laravel validation, policies and gates, Eloquent relationships,
  database constraints and transactions, PHP/native primitives, and established
  framework lifecycle APIs over handwritten duplicates.
- Do not add a package when Laravel, PHP, or PostgreSQL primitives provide the
  required semantics.
- Use a finite allowlist only when the valid domain is finite and known. Custom
  tenant, security, and business validation remains legitimate where the
  framework does not own the domain rule.
- Give every semantic invariant one authoritative definition. Independent
  enforcement is allowed at meaningful trust boundaries when it follows that
  owner.

## Required Validation And Delivery

Before a commit, push, or pull request, announce the relevant checklist and stop
on the first failed item:

- confirm branch, issue contract, and working-tree scope;
- confirm the applicable evidence rule above was followed;
- run the smallest relevant Pest coverage for changed PHP behavior;
- run `vendor/bin/pint --dirty` after PHP changes;
- run applicable static analysis, contract, Markdown, REUSE, preflight, and
  changed-file hook checks;
- update `CHANGELOG.md` for actual product fixes, features, or breaking changes,
  but not automatically for governance-only prose;
- verify that changed observable behavior, security constraints, state
  lifecycle, and error handling have corresponding evidence;
- verify commits are cryptographically signed and no bypass was used.

Use a body file for multiline `gh pr create` or `gh pr edit` content. Follow the
canonical work-graph contract for pull-request delivery, issue-closing, and
parent-reference semantics.

After a merge, return the repository to a ready state: switch to `main`, pull
with fast-forward only, delete the merged topic branch, prune remotes, refresh
Composer dependencies where applicable, run the smallest readiness command, and
confirm a clean working tree.

## Domain And Scope Notes

- Domain policy is strict: `secpal.app` is the public homepage and real-email
  domain, `apk.secpal.app` is the Android artifact and release-metadata host,
  `api.secpal.dev` is the API, `app.secpal.dev` is the PWA, `secpal.dev` is for
  development/staging/testing/examples, and `app.secpal` is only the Android
  application identifier.
- Do not add dependencies or create documentation files unless the current
  contract requires them.
