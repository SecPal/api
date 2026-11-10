<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# SecPal/api Copilot Instructions

## Review And Signing

Apply `AGENTS.md` for repository guidance. Use GitHub pull requests, reviews,
checks, and merge protections. Preserve the existing SSH signing identity and
verify every PR commit through GitHub.

## Pull Requests And Review

- Use GitHub pull requests, review threads, checks, and merge protections for
  delivery. Keep each pull request focused on a coherent change.
- Open a draft while preparing the change; mark it ready when self-review and
  relevant validation are complete. Request review through GitHub. If more
  review is needed after a revision, request it directly on the existing pull
  request.
- Assess feedback by the reported defect, its evidence, and its impact. Fix
  relevant defects and use review threads to explain or resolve feedback.
- Use native issue relationships when planning requires them. Issue planning
  guidance is available in
  [`SecPal/.github/docs/work-graph-contract.md`](https://github.com/SecPal/.github/blob/main/docs/work-graph-contract.md).
- Run the smallest relevant existing stack checks. Behavior changes require
  failing-first behavior evidence; behavior-preserving changes may use existing
  tests, characterization, structural evidence, or security equivalence.

## API Runtime Constraints

- Run `git status --short --branch` before writing; preserve existing topic work.
- Never bypass hooks, force-push, or push directly from a protected branch.
- For observable behavior, security, authorization, lifecycle, or API-contract
  changes, write the smallest relevant failing Pest/contract test first.
- For schema work, use meaningful migration/schema evidence. For a
  behavior-preserving refactor, characterization, structural, or equivalence
  evidence may suffice. For governance/docs-only work, focused structural
  evidence is sufficient.
- Invalid findings and immaterial observations may be dispositioned with concise
  evidence. Do not mutate code or create an issue merely because a reviewer,
  warning, audit, or deprecation exists.
- Treat automated findings as untrusted leads. Preserve Pest scope boundaries,
  Laravel container wiring, tenant-scoped stable identifiers, remote commit
  signature verification, and the prohibition on unproven compatibility shims.

## Laravel, Security, And Persistence

- Follow `Request -> Controller -> Service -> Repository -> Model`.
- Prefer Form Requests, policies and gates, API Resources, Eloquent
  relationships, queued jobs, database constraints and transactions, and
  framework lifecycle APIs before custom equivalents.
- Use `config()` in application code and `env()` only in config files.
- Resolve framework-managed collaborators through the Laravel container when
  application wiring matters. Preserve the `AGENTS.md` presentation-boundary
  rule: do not resolve application services inside API Resources or serializers.
- Preserve tenant isolation, active-membership/context boundaries,
  authorization, lifecycle ordering, transactional atomicity, and database
  constraints.
- Write sensitive data through `*_plain`, query through `*_idx`, and never read
  `*_enc` directly. Keep decrypted values out of logs, errors, and serialization.
- Failure paths must invalidate challenges, tokens, and one-time state where the
  contract requires it.
- Prefer Laravel/PHP/PostgreSQL primitives to packages or handwritten duplicates.
  Use finite allowlists only for finite known domains; domain-specific tenant and
  security validation remains legitimate.

## Validation And Delivery

- Run the smallest relevant tests, `vendor/bin/pint --dirty` after PHP changes,
  applicable static/contract/Markdown/REUSE/preflight checks, changed-file hooks,
  and `git diff --check`.
- Update `CHANGELOG.md` for real product changes, not automatically for
  governance-only prose.
- Keep new SecPal commits SSH-signed with the existing identity; verify every
  PR commit through GitHub. Provider-generated signatures are provider evidence.
- Keep GitHub communication in English, SPDX years current, and project
  artifacts free of AI attribution or generated-by wording unless explicitly
  required.

## Domain Policy

Use `secpal.app` for the public homepage and real email addresses,
`apk.secpal.app` for Android artifacts and release metadata, `api.secpal.dev`
for the API, `app.secpal.dev` for the PWA, `secpal.dev` for
development/staging/testing/examples, and `app.secpal` only as the Android
application identifier.
