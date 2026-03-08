<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# API Repository Instructions

These instructions are self-contained for the `api` repository at runtime.
Do not assume instructions from sibling repositories or comment-based inheritance are loaded.

## Always-On Rules

- Apply SecPal core rules on every task: TDD first, fail fast, no bypass, one topic per change, and create a GitHub issue immediately for findings that cannot be fixed in the current scope.
- Before any commit, PR, or merge, announce and verify the required checklist. Stop on the first failed check.
- Update `CHANGELOG.md` in the same change set for real fixes, features, or breaking changes.
- Keep GitHub-facing communication in English.
- Domain policy is strict: use only `secpal.app` and `secpal.dev`.
- Prefer small, root-cause fixes that match existing conventions. Do not introduce unrelated refactors.

## Repository Stack

- Laravel 12, PHP 8.4, Pest 4, PostgreSQL 16, DDEV.
- Use `ddev exec php artisan ...` for Artisan and test commands. Do not run `php artisan` directly outside DDEV.
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
