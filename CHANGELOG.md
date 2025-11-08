<!--
SPDX-FileCopyrightText: 2025 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **RBAC Phase 2 Part 1: Audit Trail Infrastructure** - Immutable log for role assignment actions (#106)
  - `role_assignments_log` table migration with UUID primary key and bigInteger foreign keys
  - `RoleAssignmentLog` model with read-only enforcement (prevents updates and deletes)
  - Action types: `assigned`, `revoked`, `expired`, `extended`
  - Relationships to User (recipient), Role, and User (assigner)
  - Temporal validity tracking (`valid_from`, `valid_until`) copied from assignments
  - Comprehensive test suite (6 tests): creation, read-only behavior, relationships
  - Prepares foundation for automatic role expiration command (Phase 2 Part 2)

### Fixed

- **RBAC Phase 1 Post-Merge Fixes** - Addressed Copilot review comments from PR #112
  - `TemporalRoleUser`: Changed `$fillable` array from `'team_id'` to `'tenant_id'` (matches Spatie Permission config)
  - `User::roles()`: Added type hint `Builder $query` to where callback for improved type safety and IDE support

### Added

- **RBAC Phase 1: Temporal Role Foundation** - Time-based role assignments with automatic expiration
  - `TemporalRoleUser` custom MorphPivot model for `model_has_roles` table
  - Temporal validity columns (`valid_from`, `valid_until`, `auto_revoke`) with migration
  - Query scopes `active()` and `expired()` for filtering roles by time
  - Helper methods `isActive()` and `isExpired()` on pivot model
  - Audit trail support (`assigned_by`, `reason`) for role assignments
  - User model override of `roles()` relationship with automatic temporal filtering
  - Shared filtering logic via `applyActiveFilter()` method (DRY principle)
  - See ADR-004 for architecture decision and Phase 2-4 roadmap
- **Tenant-Aware Temporal Role Tests** - Comprehensive test suite for RBAC Phase 1 (Issue #110)
  - 12 test cases covering temporal filtering, query scopes, helper methods, and auto-revoke logic
  - Proper multi-tenancy support with `setPermissionsTeamId()` context
  - UUID handling for `assigned_by` foreign key constraint
  - Test coverage: Temporal filtering (5 tests), Query scopes (2 tests), Helper methods (4 tests), Auto-revoke (1 test)
  - Resolves TDD compliance requirement for PR #109

### Changed

- **Preflight Script Performance**: Optimized `scripts/preflight.sh` for significantly faster local development
  - Prettier/markdownlint: Check only changed files in branch instead of all files (10-100x faster for small changes)
  - composer/npm/pnpm/yarn: Skip dependency installation if lockfile unchanged and vendor/node_modules exists (saves minutes per push)
  - npm audit: Only run after fresh install, skip when dependencies unchanged (saves 5-10s network call)
  - git fetch: Cache for 5 minutes with 30s timeout to prevent hanging on slow networks
  - Expected improvement: 90s → 15s for small fixes, 120s → 35s for features without dependency changes
  - All quality gates remain enforced: Pint, PHPStan, Tests, Prettier, Markdownlint, REUSE

### Fixed

- Project automation now triggers on label changes for issues AND pull requests (labeled event)
- Pre-push hook no longer fails with exit code 1 when [Unreleased] is the last CHANGELOG section

### Added

- German translations for password reset emails
- JSON-based translation files (`lang/de.json`) for German language support
- Localized password reset email template using `__()` helper functions
- 5 comprehensive tests for password reset email translations
- `SetLocaleFromHeader` middleware for Accept-Language header detection
- Automatic locale switching based on HTTP Accept-Language header
- Support for multi-language API responses (English, German)
- 6 comprehensive tests for locale middleware functionality
- Translation.io integration for multi-language support (en, de)
- Configuration file `config/translation.php` for Translation.io
- `TRANSLATIONIO_KEY` environment variable for API key management
- Translation management via `php artisan translation:*` commands
- Pint `--test --dirty` workflow in preflight script for CI parity
- Pre-commit hook for Laravel Pint auto-formatting
- CHANGELOG validation in preflight script
- Initial Laravel 12 setup with PostgreSQL support
- PEST testing framework integration
- PHPStan static analysis with Larastan
- Laravel Pint code style checking
- REUSE 3.3 compliance
- GitHub Actions CI/CD workflows
- Pre-commit and pre-push hooks
- API-only configuration (no frontend scaffolding)
- Comprehensive documentation

[unreleased]: https://github.com/SecPal/api/commits/main
