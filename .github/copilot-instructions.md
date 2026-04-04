<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# API Repository Instructions

These instructions are self-contained for the `api` repository at runtime.
Do not assume instructions from sibling repositories or comment-based inheritance are loaded.

## Always-On Rules

- Run `git status --short --branch` before any write action. Never start implementation on local `main`, and stop if a dirty non-`main` branch contains unrelated work.
- Keep one topic per change, fail fast, and never use bypasses such as `--no-verify` or force-push.
- Update `CHANGELOG.md` in the same change set for real fixes, features, and breaking changes.
- Create a GitHub issue immediately for out-of-scope bugs, technical debt, missing tests, documentation gaps, and actionable warnings you cannot fix now.
- Keep GitHub-facing communication in English and reference files and lines instead of pasting large code blocks.
- Treat warnings, audit findings, and deprecations as actionable. Fix them in scope or track them immediately.
- Never reply to Copilot review comments with GitHub comment tools. Fix the code, push, and resolve threads through the approved non-comment workflow.
- Use EPIC plus sub-issues before starting work that will span more than one PR.
- Keep `SPDX-FileCopyrightText` years current in edited files or companion `.license` sidecars.
- Domain policy is strict: `secpal.app` for the public homepage and real email addresses, `api.secpal.dev` for the API, `app.secpal.dev` for the PWA/frontend, `secpal.dev` for dev, staging, testing, and examples, and `app.secpal` only as the Android application identifier.

## Required Validation

Before any commit, PR, or merge, announce the checklist you are executing and stop on the first failed item.
At minimum verify:

- the smallest relevant Pest coverage for the touched area passed
- `vendor/bin/pint --dirty` ran after PHP changes
- `CHANGELOG.md` was updated for real changes
- commits are GPG-signed
- REUSE compliance was checked when changed files require it
- the local 4-pass review was completed
- no bypass was used

## Repository Conventions

- Stack: Laravel 13, PHP 8.4, Pest 4, PostgreSQL 16, native PHP shell usage.
- Use direct framework commands such as `php artisan ...`, `php artisan test`, and `composer ...`.
- Follow `Request -> Controller -> Service -> Repository -> Model`.
- Prefer Form Requests, policies, API resources, queued jobs, and Eloquent relationships before hand-rolled alternatives.
- Protect personal data at rest: write through `*_plain`, use `*_idx` only for queries, and never read `*_enc` directly.

## Scope Notes

- Do not add dependencies or create documentation files unless the task requires them.
