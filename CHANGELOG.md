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

- **User Language Preference** (#86)
  - New `preferred_locale` column in `users` table (VARCHAR(5), nullable)
  - PATCH `/v1/me/language` endpoint to update user's preferred language
  - Supports `en` (English) and `de` (German)
  - Can be set to `null` to use default/Accept-Language header
  - Form request validation via `UpdateUserLanguageRequest`
  - 8 comprehensive feature tests
  - Database migration: `2025_11_16_192506_add_preferred_locale_to_users_table`

- **Secret Sharing & Access Control (Phase 3)** (#182)
  - **Secret CRUD API**: Full REST API for password manager functionality
    (#187)
    - Create secrets with encrypted title, username, password, URL, notes
      (POST `/v1/secrets`)
    - List user's secrets with filter parameter: `all` (default), `owned`, `shared`
      (via SecretShare) (GET `/v1/secrets?filter={type}`)
    - Filter validation via `IndexSecretRequest` (rejects invalid filter values)
    - Query optimization: Role IDs cached to avoid N+1 queries
    - DRY implementation: Shared filter logic extracted to `Secret::scopeSharedWith()`
    - Empty role array optimization: Skips `orWhereIn` when user has no roles
    - View secret details with owner or share-based access (GET
      `/v1/secrets/{secret}`)
    - Update secrets with automatic version incrementing (PATCH
      `/v1/secrets/{secret}`)
    - Soft delete secrets (DELETE `/v1/secrets/{secret}`)
    - Authorization via `SecretPolicy` with 9 methods: viewAny, view, create,
      update, delete, restore, forceDelete, share, viewShares
    - Permission hierarchy: admin > write > read (via
      `Secret::userHasPermission()`)
    - Share-based access respects expiration dates and permission levels
    - Validation via `StoreSecretRequest`, `UpdateSecretRequest`, `IndexSecretRequest`
    - 22 comprehensive Controller tests covering CRUD + share-based access
      scenarios
  - **Secret Sharing API**: Grant/revoke access to secrets
    - Grant read/write/admin access to users OR roles (POST `/v1/secrets/{secret}/shares`)
    - List all shares for a secret (GET `/v1/secrets/{secret}/shares`)
    - Revoke share access (DELETE `/v1/secrets/{secret}/shares/{share}`)
    - XOR constraint validation: cannot grant to both user AND role
    - Optional expiration dates for time-limited access
    - Permission hierarchy: admin > write > read
    - Authorization via `SecretSharePolicy` (owner-only for now)
    - 18 comprehensive Controller tests covering all scenarios
  - **Database Foundation** (already merged):
    - `secret_shares` table with XOR constraint
    - `SecretShare` model with relationships and scopes
    - Migration tests and model tests (13 total)
  - **Total Test Coverage**: 35 Controller tests, 13 Model tests, all passing
  - **Note**: Tenant resolution uses temporary `TenantKey::first()` pattern (TODO: TenantMiddleware)

- **File Attachments API (Phase 2)** (#175)
  - Upload encrypted file attachments to secrets (POST `/v1/secrets/{secret}/attachments`)
  - List attachments for a secret (GET `/v1/secrets/{secret}/attachments`)
  - Download decrypted attachments (GET `/v1/attachments/{attachment}/download`)
  - Delete attachments (DELETE `/v1/attachments/{attachment}`)
  - Files encrypted at rest using tenant DEK encryption
  - Configurable file size limits and MIME type restrictions
  - Owner-based authorization via `SecretAttachmentPolicy`
  - OpenAPI documentation for all attachment endpoints
  - Comprehensive test coverage: 13 Controller tests, 3 Service tests, 2 Model tests, 8 Policy tests

- **Code Coverage Integration** (#170)
  - Integrated Codecov for automated coverage tracking
  - PHPUnit now generates Clover XML coverage reports
  - CI pipeline uploads coverage to Codecov dashboard
  - Added coverage badge to README.md
  - Xdebug coverage enabled in GitHub Actions
  - Supports organization-wide 80% coverage threshold

### Fixed

- **Permission Naming Conflict** (#108, Phase 4)
  - Fixed missing `role.assign`, `role.read`, `role.revoke` permissions in seeder
  - Phase 3 routes (`POST /v1/users/{user}/roles`) require `role.assign` permission
  - Seeder was only creating `roles.assign_temporary` (Phase 4 naming)
  - Admin role now has both `role.*` (Phase 3) and `roles.*` (Phase 4) permissions
  - Enables integration tests that were previously blocked by authorization failures
  - Resolves 403 Forbidden errors when admins assign roles to users

### Added

- **Role Management CRUD API** (#108, Phase 4)
  - New endpoint: `GET /v1/roles` - List all roles with permission count and user count
  - New endpoint: `POST /v1/roles` - Create new role with permissions
  - New endpoint: `GET /v1/roles/{id}` - Get role details with assigned permissions
  - New endpoint: `PATCH /v1/roles/{id}` - Update role name and/or permissions
  - New endpoint: `DELETE /v1/roles/{id}` - Delete role (blocks if assigned to users)
  - New controller: `RoleManagementController` - Handles role CRUD operations
  - New policy: `RoleManagementPolicy` - Admin-only authorization for all operations
  - New form requests: `CreateRoleRequest`, `UpdateRoleRequest` - Validation rules
  - Simple role system: All roles equal, no artificial system/custom distinction
  - Deletion protection: Cannot delete roles assigned to users (422 response with user count)
  - Part of RBAC Phase 4 Epic (#108), completes role management capabilities

- **Predefined Roles Seeder** (#108, Phase 4)
  - New seeder: `RolesAndPermissionsSeeder` - Creates 5 predefined roles with permissions
  - Predefined roles: Admin, Manager, Guard, Client, Works Council
  - Idempotent design: Safe to run multiple times, uses `firstOrCreate`
  - Auto-recreation: Deleted predefined roles are recreated on next seeder run
  - Permission groups: 52 permissions across 7 resources (employees, shifts, work_instructions, roles, permissions, works_council, reports)
  - Wildcard expansion: Supports `resource.*` notation for assigning all resource actions
  - Only syncs permissions if role has none (prevents overwriting customizations)
  - Part of RBAC Phase 4 Epic (#108), provides production-ready role foundation

- **RBAC Documentation** (#108, Phase 4)
  - New guide: `docs/guides/role-management.md` - How to create/manage roles (872 lines)
  - New guide: `docs/guides/permission-system.md` - Permission naming conventions and organization (716 lines)
  - New guide: `docs/guides/temporal-roles.md` - Temporal role assignment patterns
  - New guide: `docs/guides/direct-permissions.md` - When and how to use direct permissions
  - New API docs: `docs/api/rbac-endpoints.md` - Complete API reference for all 16 RBAC endpoints (1239 lines)
  - Comprehensive examples: Request/response samples for all endpoints
  - Authorization diagrams: Visual representation of permission checks
  - Best practices: Guidelines for role design and permission management
  - Part of RBAC Phase 4 Epic (#108), completes RBAC documentation requirements

- **User Direct Permission Assignment API** (#138)
  - New endpoint: `GET /v1/users/{user}/permissions` - List all user permissions (direct + inherited from roles)
  - New endpoint: `POST /v1/users/{user}/permissions` - Assign direct permission(s) to user with temporal tracking (audit trail)
  - New endpoint: `DELETE /v1/users/{user}/permissions/{permission}` - Revoke direct permission from user
  - New endpoint: `GET /v1/users/{user}/permissions/direct` - List only direct permissions (excludes permissions inherited from roles)
  - Uses existing pivot columns (`granted_at`, `granted_by`, `revoked_at`, `revoked_by`) on `model_has_permissions` table for temporal direct permission assignment (no new migration required)
  - New controller: `UserPermissionController` - Handles direct permission assignment operations
  - New policy: `UserPermissionPolicy` - Authorization rules (User can view own, Admin can assign/revoke)
  - New form request: `AssignUserPermissionRequest` - Validates permission existence, not already assigned, and assignment metadata
  - New method: `User::hasDirectPermission()` - Check if permission is directly assigned (not via roles)
  - Part of RBAC Phase 4 Epic (#108), enables fine-grained permission control bypassing roles

- **Permission Management CRUD API** (#137)
  - New endpoint: `GET /v1/permissions` - List all permissions grouped by resource
  - New endpoint: `POST /v1/permissions` - Create new permission with strict naming validation (resource.action)
  - New endpoint: `GET /v1/permissions/{id}` - Get permission details with assigned roles
  - New endpoint: `PATCH /v1/permissions/{id}` - Update permission description (name is immutable)
  - New endpoint: `DELETE /v1/permissions/{id}` - Delete permission (blocks if assigned to roles)
  - New migration: Add `description` column to `permissions` table
  - New model: `App\Models\Permission` - Extended Spatie Permission model with description support
  - New policy: `PermissionManagementPolicy` - Admin-only authorization for all operations
  - New form requests: `CreatePermissionRequest`, `UpdatePermissionRequest` - Validation rules
  - Part of RBAC Phase 4 Epic (#108), enables Issue #138 (User Direct Permission Assignment)

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

- **Markdown Linting** - Fixed 88 markdown-lint errors in `.github/copilot-instructions.md` (#123)
  - Added blank lines around headings (MD022)
  - Added blank lines around lists (MD032)
  - Removed multiple consecutive blank lines (MD012)
  - Fixed unordered list indentation to 2 spaces (MD007)
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
  - `POST /v1/users/{id}/roles` - Assign role with temporal parameters (valid_from, valid_until, auto_revoke)
  - `GET /v1/users/{id}/roles` - List user roles with expiry info (is_active, is_expired status)
  - `DELETE /v1/users/{id}/roles/{role}` - Revoke role assignment
  - `PATCH /v1/users/{id}/roles/{role}/extend` - Extend role expiration date
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
