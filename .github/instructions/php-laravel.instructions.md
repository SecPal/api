---
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: CC0-1.0
name: Laravel PHP Rules
description: Applies Laravel, Pest, and native PHP runtime rules to PHP work in the API repository.
applyTo: "**/*.php,artisan"
---

# Laravel PHP Rules

- Follow Laravel conventions before custom patterns.
- Use Form Requests for validation, services for business logic, and Eloquent relationships before raw queries.
- Use direct framework commands such as `php artisan ...` and `php artisan make:* --no-interaction` in the active shell.
- Add or update the smallest relevant Pest test for each PHP change, then run the affected tests.
- Use explicit return types, descriptive names, eager loading when appropriate, and `vendor/bin/pint --dirty` after changes.
- Use `config()` in application code and keep `env()` confined to config files.
- In Pest files, keep executable statements inside `beforeEach`, `it`/`test`, or helper functions; never insert side
  effects at file scope.
- For AI-suggested security-flow fixes, verify failure paths also invalidate challenges, tokens, or other one-time
  state and add regression coverage.
- Resolve application services, observers, and framework-managed collaborators through the container in tests when
  behavior depends on app wiring.
