---
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: CC0-1.0
name: Laravel PHP Rules
description: Applies Laravel, Pest, and native PHP runtime rules to PHP work in the API repository.
applyTo: "**/*.php,artisan"
---

# Laravel PHP Rules

- Follow Laravel and PHP standards before custom patterns.
- Prefer Form Requests, policies and gates, Eloquent relationships, database constraints and transactions,
  and framework lifecycle APIs before handwritten equivalents.
- Do not add a package when Laravel, PHP, or PostgreSQL primitives suffice.
- Use direct framework commands such as `php artisan ...` and `php artisan make:* --no-interaction` in the active shell.
- For observable behavior, security, authorization, lifecycle, or API-contract
  changes, add or update the smallest relevant failing Pest/contract test first.
  Behavior-preserving refactors may use existing behavior, characterization,
  structural, or equivalence evidence instead.
- Use explicit return types, descriptive names, eager loading when appropriate, and `vendor/bin/pint --dirty` after changes.
- Use `config()` in application code and keep `env()` confined to config files.
- In Pest files, keep executable statements inside `beforeEach`, `it`/`test`, or helper functions; never insert side
  effects at file scope.
- For AI-suggested security-flow fixes, verify failure paths also invalidate challenges, tokens, or other one-time
  state and add regression coverage.
- Resolve application services, observers, and framework-managed collaborators
  through the Laravel container when behavior depends on app wiring.
- Use finite allowlists only for finite known domains. Custom tenant, security,
  and business validation remains legitimate where Laravel does not own the
  domain rule.
