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

- **RBAC Architecture Documentation** (#143)
  - New file: `docs/rbac-architecture.md` - Central RBAC system documentation
  - System architecture: High-level component diagrams (Users → Roles → Permissions + Direct Permissions)
  - Core concepts: Roles, Permissions, Direct Permissions, Temporal Assignments
  - Design principles: Links to ADR-005 (No System Roles, Direct Permissions, Temporal Optional)
  - Permission hierarchy: Formula and examples showing Role ∪ Direct permission resolution
  - Implementation patterns: 5+ code examples for role/permission assignment and checking
  - API overview: Summary of 4 API areas (Role Assignment, Role Management, Permission Management, Direct Permissions)
  - Developer guidelines: Decision trees, best practices, testing strategies
  - Serves as single source of truth for RBAC system understanding
  - Part of Epic #141 (Complete RBAC Documentation), depends on ADR-005, blocks Issues #144, #145, #137-140

- **Guard Architecture Documentation** (#130)
  - New file: `docs/GUARD_ARCHITECTURE.md` - Comprehensive guide to Laravel Guards in SecPal
  - Explains guard concept: authentication mechanisms (session vs token-based)
  - Documents SecPal's architecture decision: Why `sanctum` guard exclusively
  - Spatie Permission integration: How guard-awareness works, guard mismatch troubleshooting
  - Configuration walkthrough: `config/auth.php`, User model `$guard_name`, route middleware
  - Developer guidelines: Best practices for creating permissions/roles with correct guard
  - Migration context: Historical background and EPIC #125 systematic migration
  - Code examples: Correct vs incorrect patterns for permissions, roles, tests
  - Troubleshooting section: Common errors and debug steps
  - Incorporates insights from Issue #134 and PR #135 (explicit route middleware best practice)

### Changed

- **Auth Configuration: Set sanctum as default guard** (Issue #134, PR #135)
  - Changed default guard from `'web'` to `'sanctum'` in `config/auth.php`
  - Added explicit `sanctum` guard configuration to guards array
  - Updated documentation comments to explain API-only, token-based architecture
  - Aligns configuration with actual authentication mechanism (all routes use `auth:sanctum`)
  - Self-documenting: Config now clearly shows SecPal is API-only (React PWA frontend)
  - Consistent with User model `$guard_name = 'sanctum'` property (#129)
  - No behavior change: All 207 tests passing

### Fixed

- **Permission System Guard Migration** - Migrated from 'web' to 'sanctum' guard (#126, #127, #128, #129)
  - Fixed `RoleApiTest.php` - Added explicit `guard_name='sanctum'` to all Permission and Role creation
  - Fixed `PersonApiTest.php` - Changed `guard_name` from 'web' to 'sanctum' for person permissions
  - Added `$guard_name = 'sanctum'` property to User model for Spatie Laravel-Permission
  - Resolves 403 Forbidden errors caused by guard mismatch between sanctum authentication and web permissions
  - All 40 tests now passing (146 assertions)

### Added

- **Git Conflict Marker Detection** - Automated check for unresolved merge conflicts
  - `scripts/check-conflict-markers.sh` - Scans all tracked files for conflict markers (`<<<<<<<`, `=======`, `>>>>>>>`, `|||||||` with space)
  - `.github/workflows/check-conflict-markers.yml` - CI integration (runs on all PRs and pushes to main)
  - `docs/scripts/CHECK_CONFLICT_MARKERS.md` - Complete usage guide with examples and troubleshooting
  - Exit codes: 0 = clean, 1 = conflicts detected
  - Prevents accidental commits of broken code from merge conflicts
  - Colored output shows exact file locations and line numbers
- **RBAC Phase 3: API Endpoints & Authorization** - Role management REST API (#107)
  - `POST /api/v1/users/{id}/roles` - Assign role with temporal parameters (valid_from, valid_until, auto_revoke)
  - `GET /api/v1/users/{id}/roles` - List user roles with expiry info (is_active, is_expired status)
  - `DELETE /api/v1/users/{id}/roles/{role}` - Revoke role assignment
  - `PATCH /api/v1/users/{id}/roles/{role}/extend` - Extend role expiration date
  - `RoleController` with 3 RESTful methods (`store`, `index`, `destroy`) and 1 custom action (`extend`)
  - `AssignRoleRequest` - Validates temporal parameters (valid_from < valid_until, role existence)
  - `ExtendRoleRequest` - Validates extension (new date must be after current expiration)
  - Permission-based authorization: `role.assign`, `role.read`, `role.revoke` permissions required
  - Audit logging: All actions (assigned, revoked, extended) logged to `role_assignments_log`
  - Transaction safety: All mutations wrapped in database transactions
  - ISO 8601 timestamps in API responses for international compatibility

### Changed

- **RBAC: Optimized ExpireRoles Command** - Performance and concurrency improvements (#119)
  - Removed `declare(strict_types=1)` for consistency with other console commands
  - Switched from per-item transactions to batch processing (100 items per transaction)
  - Implemented delete-first-then-log pattern to prevent duplicate audit logs on concurrent execution
  - Changed from `get()` to `cursor()` for memory-efficient streaming without primary key dependency
  - Added `processChunk()` helper method for cleaner separation of concerns
  - Comprehensive test coverage for chunk boundaries (100, 101, 250 roles) and race conditions
  - No memory issues when processing 1000+ expired roles (tested with 250 roles)
  - Prevents duplicate audit logs when multiple scheduler instances run simultaneously

### Added

- **RBAC Phase 2: Temporal Logic & Auto-Expiration** - Automatic expiration of time-limited role assignments (#106)
  - `roles:expire` scheduled command runs every minute to revoke expired roles
  - Only processes roles with `auto_revoke=true` flag set
  - Logs all expirations to immutable `role_assignments_log` audit trail before deletion
  - Transaction-based processing ensures data integrity (log → delete)
  - Comprehensive test suite (10 tests): expiration logic, audit logging, timezone handling, batch processing
  - Scheduler configuration in `routes/console.php` for automatic execution
  - PHPDoc annotations for pivot model properties (PHPStan compliance)
  - Database constraint fix: `assigned_by` column type changed from UUID to bigInteger (matches User table)
- **RBAC Phase 2 Part 1: Audit Trail Infrastructure** - Immutable log for role assignment actions (#106)
  - `role_assignments_log` table migration with UUID primary key and bigInteger foreign keys
  - `RoleAssignmentLog` model with read-only enforcement (prevents updates and deletes)
  - Action types: `assigned`, `revoked`, `expired`, `extended`
  - Relationships to User (recipient), Role, and User (assigner)
  - Temporal validity tracking (`valid_from`, `valid_until`) copied from assignments
  - Comprehensive test suite (6 tests): creation, read-only behavior, relationships
  - Prepares foundation for automatic role expiration command (Phase 2 Part 2)

### Fixed

- **PHPStan Memory Configuration** - Documented memory limit in phpstan.neon (#115)
  - Added documentation comment noting that `scripts/preflight.sh` uses 512M memory limit
  - Prevents confusion about memory configuration location
  - Memory limit already sufficient for current codebase (51+ files analyzed successfully)
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
