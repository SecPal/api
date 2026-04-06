<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# API Repository Instructions

These instructions are self-contained for the `api` repository at runtime.
Do not assume instructions from sibling repositories or comment-based inheritance are loaded.

## Always-On Rules

- Run `git status --short --branch` before any write action. New work must start
  from a clean, up-to-date local `main`: switch to `main`, pull with
  fast-forward only, verify a clean state, then create the dedicated topic
  branch. Never start implementation on local `main`, and stop if a dirty
  non-`main` branch contains unrelated work.
- TDD is mandatory for behavior and code changes. Write or update the smallest relevant failing Pest test FIRST, then implement, then refactor with tests green.
- Quality first. Do not trade correctness, review depth, validation depth, or issue tracking for speed.
- Keep one topic per change. 1 topic = 1 PR = 1 branch. Do not mix unrelated fixes, features, refactors, docs, or governance cleanup.
- Never use bypasses such as `--no-verify` or force-push.
- Update `CHANGELOG.md` in the same change set for real fixes, features, and breaking changes.
- Create a GitHub issue immediately for every real out-of-scope bug, technical debt, missing test, documentation gap, warning, audit finding, or deprecation you cannot fix now. Do not leave untracked `TODO`, `FIXME`, or follow-up work.
- Use EPIC plus sub-issues before implementation whenever work will span more than one PR; if in doubt, choose EPIC plus sub-issues.
- Keep GitHub-facing communication in English and reference files and lines instead of pasting large code blocks.
- Treat warnings, audit findings, and deprecations as actionable. Fix them in scope or track them immediately.
- Never reply to Copilot review comments with GitHub comment tools. Fix the code, push, and resolve threads through the approved non-comment workflow.
- Keep `SPDX-FileCopyrightText` years current in edited files or companion `.license` sidecars.
- Domain policy is strict: `secpal.app` for the public homepage and real email addresses, `apk.secpal.app` for the canonical Android artifact and release-metadata host, `api.secpal.dev` for the API, `app.secpal.dev` for the PWA/frontend, `secpal.dev` for dev, staging, testing, and examples, and `app.secpal` only as the Android application identifier.
- After every merge, immediately return the local repo to a ready state:
  switch to `main`, pull with fast-forward only, delete the merged topic
  branch, prune remotes, refresh Composer dependencies where applicable, run
  the smallest relevant API readiness command, and confirm the working tree is
  clean.

## Design Principles

- DRY: eliminate duplicated logic and repeated policy handling before it drifts.
- KISS: prefer the simplest solution that satisfies the current requirement and remains easy to maintain.
- YAGNI: implement only what the current issue or acceptance criteria require; track future ideas as issues instead of building them now.
- SOLID: keep responsibilities narrow, interfaces small, and extension points explicit.
- Fail fast: validate early, stop on the first failed check, and do not accumulate known breakage.

## Issue And PR Discipline

- Every real out-of-scope finding becomes a GitHub issue immediately; no untracked follow-up work is allowed.
- Complex work uses EPIC plus sub-issues before implementation. PRs should close sub-issues, not the epic, until the final linked step.
- When local review finds zero issues, commit and push the finished branch before opening any PR.
- The first PR state must be draft. Do not open a normal PR first.
- Mark a draft PR ready only after the final self-review in the PR view still finds zero issues.
- When using GitHub CLI (`gh pr create` or `gh pr edit`) to create or edit PRs, write multi-line body content to a file and use `--body-file` to prevent shell escaping issues.

## Required Validation

Before any commit, PR, or merge, announce the checklist you are executing and stop on the first failed item.
At minimum verify:

- the active branch and PR scope still address exactly one topic
- TDD happened for any behavior or code change: the relevant Pest test failed first and now passes
- the smallest relevant Pest coverage for the touched area passed
- `vendor/bin/pint --dirty` ran after PHP changes
- out-of-scope findings were turned into GitHub issues immediately
- `CHANGELOG.md` was updated for real changes
- commits are GPG-signed
- REUSE compliance was checked when changed files require it
- when a fix alters observable behavior, state lifecycle, error handling, or security constraints, the corresponding tests were identified and updated in the same commit
- before pushing behavioral or security-critical changes, affected tests were run locally (`PREFLIGHT_RUN_TESTS=1 git push` or invoke the test runner directly)
- the local 4-pass review was completed, including DRY, KISS, YAGNI, SOLID, quality-first, and issue-management checks
- no bypass was used

## Repository Conventions

- Stack: Laravel 13, PHP 8.4, Pest 4, PostgreSQL 16, native PHP shell usage.
- Use direct framework commands such as `php artisan ...`, `php artisan test`, and `composer ...`.
- Follow `Request -> Controller -> Service -> Repository -> Model`.
- Prefer Form Requests, policies, API resources, queued jobs, and Eloquent relationships before hand-rolled alternatives.
- Protect personal data at rest: write through `*_plain`, use `*_idx` only for queries, and never read `*_enc` directly.

## Scope Notes

- Do not add dependencies or create documentation files unless the task requires them.
