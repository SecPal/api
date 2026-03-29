<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# API Repository Instructions

These instructions are self-contained for the `api` repository at runtime.
Do not assume instructions from sibling repositories or comment-based inheritance are loaded.

## Always-On Rules

- Apply SecPal core rules on every task: TDD first, fail fast, no bypass, one topic per change, and create a GitHub issue immediately for findings that cannot be fixed in the current scope.
- Apply branch hygiene from the first local change: inspect branch state before
  work, never start on local `main`, and do not mix unrelated uncommitted
  changes into the current task.
- Before any commit, PR, or merge, announce and verify the required checklist. Stop on the first failed check.
- Update `CHANGELOG.md` in the same change set for real fixes, features, or breaking changes.
- Keep GitHub-facing communication in English.
- Domain policy is strict: use `secpal.app` only for the public homepage and
  real email addresses, `api.secpal.dev` for the API, `app.secpal.dev` for the
  PWA/frontend, and `secpal.dev` for dev, staging, testing, and examples.
  Treat `api.secpal.app` and `app.secpal.app` as deprecated web hosts;
  `app.secpal.app` remains valid only as the Android application identifier.
- Never reply to Copilot review comments with GitHub comment tools. Fix the
  code, push, and resolve review threads through the approved non-comment
  workflow.
- For work that needs more than one PR, create an EPIC with linked sub-issues
  before implementation.
- Do not paste large verbatim code blocks into GitHub comments, issues, or PR
  descriptions. Reference file paths and line numbers instead.
- Treat warnings, audit findings, deprecation notices, and similar non-fatal
  diagnostics from scripts, `composer`, `npm`, and related tooling as
  actionable: review them, fix them in scope, or create a GitHub issue
  immediately if they are real but out of scope.
- Prefer small, root-cause fixes that match existing conventions. Do not introduce unrelated refactors.
- When editing a file or license sidecar that contains
  `SPDX-FileCopyrightText`, keep the year current: use a single year such as
  `2026` if it is already the current year, otherwise extend it to a no-spaces
  range ending in the current year such as `2025-2026`. If the edited file has
  no inline header but a companion `.license` file exists, check and update
  that `.license` file instead.

## Branch Hygiene

- Before any edit or other write action, run `git status --short --branch` and
  understand the current branch plus local changes.
- Never start implementation on local `main`. Create or switch to a dedicated topic branch first.
- If a non-`main` branch already contains uncommitted changes, continue only
  when they belong to the same task.
- If existing changes are unrelated or unclear, stop and ask whether they
  should be committed, stashed, or split before proceeding.
- Never create mixed commits by reusing a dirty branch for a new topic.

## Required Checklist

Before any commit, PR, or merge, announce and verify at least:

- the smallest relevant Pest tests passed for the affected area
- `CHANGELOG.md` was updated in the same change set for real changes
- commits are GPG-signed
- REUSE compliance was checked when the changed files require it
- the local 4-pass review was completed before creating a PR
- tooling warnings and audit/deprecation notices were reviewed and either fixed
  or tracked immediately
- no bypass was used, including `--no-verify` or force-push
- repo-local instructions remain self-contained and do not rely on cross-repo inheritance
- out-of-scope findings were turned into GitHub issues immediately

## Repository Stack

- Laravel 13, PHP 8.4, Pest 4, PostgreSQL 16, running in a native PHP environment locally or on the remote VPS over SSH.
- Use direct commands such as `php artisan ...`, `php artisan test`, and `composer ...` in the active shell. Do not assume environment-specific wrappers are available.
- Use `vendor/bin/pint --dirty` after PHP changes.

## Architecture

- Follow `Request -> Controller -> Service -> Repository -> Model`.
- Controllers orchestrate only. Put validation into Form Request classes.
- Put business logic into services and data access into repositories or models.
- Prefer Eloquent relationships, API resources, policies, and queued jobs over ad hoc logic.

## Data Protection

- All personal data must be encrypted at rest.
- `*_enc`: encrypted storage, never access directly.
- `*_idx`: blind index for search and filtering only.
- `*_plain`: transient write-only property used in controllers, tests, and factories.

## Laravel And Testing Rules

- Search Laravel ecosystem documentation before changing Laravel-specific behavior.
- Use `php artisan make:* --no-interaction` for framework-generated files.
- Use Pest for every code change. Add or update the smallest relevant test first, then run the affected tests.
- Use explicit return types, descriptive names, and curly braces for all control structures.
- Use `config()` outside config files. Do not call `env()` in application code.
- Prefer Form Requests, policies, eager loading, and API resources over hand-rolled alternatives.

## Scope Notes

- Do not add dependencies or create documentation files unless the task requires it.
- If a UI change is not visible, the likely next step is `npm run build`, `npm run dev`, or `composer run dev`.
