---
# SPDX-FileCopyrightText: 2026 SecPal
# SPDX-License-Identifier: AGPL-3.0-or-later
name: Laravel PHP Rules
description: Applies Laravel, Pest, and DDEV rules to PHP work in the API repository.
applyTo: "**/*.php,artisan"
---

# Laravel PHP Rules

- Follow Laravel conventions before custom patterns.
- Use Form Requests for validation, services for business logic, and Eloquent relationships before raw queries.
- Use `ddev exec php artisan ...` and `php artisan make:* --no-interaction` for framework commands.
- Add or update the smallest relevant Pest test for each PHP change, then run the affected tests.
- Use explicit return types, descriptive names, eager loading when appropriate, and `vendor/bin/pint --dirty` after changes.
- Use `config()` in application code and keep `env()` confined to config files.
