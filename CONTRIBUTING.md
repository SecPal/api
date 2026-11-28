<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Contributing to SecPal API

Use GitHub issues and native relationships for scope and dependencies. Follow
the shared contribution guide for signed commits, review and protected
delivery. The API instructions define repository-specific evidence and
validation. [SecPal contribution guide](https://github.com/SecPal/.github/blob/main/CONTRIBUTING.md) ·
[API repository instructions](AGENTS.md)

## Setup and checks

Use the README quick start for a standalone development checkout. Use the
approved workspace setup for an issue-bound assignment.
[API quick start](README.md#quick-start) ·
[Workspace setup](https://github.com/SecPal/.github/blob/main/WORKSPACE_SETUP.md)

Use Node.js 26 for repository tooling. The quality workflow records the exact
CI version. [Quality workflow](.github/workflows/quality.yml)

In a standalone clone, install pre-commit and both repository hooks. Run
preflight before pushing:

```bash
./scripts/setup-pre-commit.sh
./scripts/setup-pre-push.sh
./scripts/preflight.sh
```

Use `PREFLIGHT_RUN_TESTS=1 ./scripts/preflight.sh` to include the full PHP
test suite. Use the development guide for test-database setup and focused
local checks. [Development and tests](DEVELOPMENT.md)

## Pull requests

Keep one coherent topic together with its tests and documentation. Open a
draft while work is in progress and mark it Ready when the applicable checks
pass. Request another review when a revision invalidates the earlier review.
Use a closing reference for an issue the pull request completes.

The PR-size check warns above 600 changed lines after exclusions.
Explain the review scope for a large cohesive diff. [PR-size workflow](.github/workflows/pr-size.yml)

Preserve file-level SPDX licensing and run `reuse lint` when modifying files.
[REUSE metadata](REUSE.toml) · [Third-party notices](THIRD-PARTY-NOTICES.md)
