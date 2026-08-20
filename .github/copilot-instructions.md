<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# SecPal/api Copilot Instructions

`AGENTS.md` is the authoritative API runtime baseline. This compatibility mirror
summarizes the same authority boundary for tools that load this path.

## Governance Authority

[`SecPal/.github/docs/work-graph-contract.md`](https://github.com/SecPal/.github/blob/main/docs/work-graph-contract.md)
is the single organization-wide owner of generic work-graph and engineering
governance semantics. Follow it for native hierarchy and dependencies, delivery
contracts, primary pull requests, canonical finding classification, replanning,
review, evidence, and stop conditions. Do not redefine those semantics locally.

GitHub-native issue state and relationships are authoritative. API prose owns
contract and security detail, not graph sequence, readiness, or progress.

## API Runtime Constraints

- Run `git status --short --branch` before writing; preserve existing topic work.
- Never bypass hooks, force-push, or push directly from a protected branch.
- Keep work inside the current contract and use canonical replanning for a real
  prerequisite or independent responsibility.
- For observable behavior, security, authorization, lifecycle, or API-contract
  changes, write the smallest relevant failing Pest/contract test first.
- For schema work, use meaningful migration/schema evidence. For a
  behavior-preserving refactor, characterization, structural, or equivalence
  evidence may suffice. For governance/docs-only work, focused structural
  evidence is sufficient.
- Apply the canonical review and evidence stop conditions; do not require
  unbounded observation or proof.
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
  application wiring matters.
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
- Keep commits cryptographically signed and create the first PR as Draft.
- Follow the canonical contract for delivery references; no PR closes an epic.
- Keep GitHub communication in English, SPDX years current, and project
  artifacts free of AI attribution or generated-by wording unless explicitly
  required.

## Domain Policy

Use `secpal.app` for the public homepage and real email addresses,
`apk.secpal.app` for Android artifacts and release metadata, `api.secpal.dev`
for the API, `app.secpal.dev` for the PWA, `secpal.dev` for
development/staging/testing/examples, and `app.secpal` only as the Android
application identifier.
