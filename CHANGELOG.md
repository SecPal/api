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

- **Employee Management RESTful API Endpoints** (#323, Phase 5 of Epic #211)

  - Implemented 5 REST controllers with 30+ endpoints for complete employee lifecycle management:
    - `EmployeeController`: 7 methods (index, store, show, update, destroy, activate, terminate)
      - GET /v1/employees: Paginated list with filters (status, organizational_unit_id, search)
      - POST /v1/employees: Create employee with auto-generated employee_number (EMP-YYYY-####)
      - GET /v1/employees/{employee}: Fetch employee with relationships
      - PATCH /v1/employees/{employee}: Update employee data
      - DELETE /v1/employees/{employee}: Soft delete employee
      - POST /v1/employees/{employee}/activate: Transition pre-contract → active (validates onboarding completion)
      - POST /v1/employees/{employee}/terminate: Transition active/on_leave → terminated
    - `QualificationController`: 5 methods (index, store, show, update, destroy)
      - Manages system qualifications (14 predefined) + tenant-specific custom qualifications
      - Prevents modification/deletion of system qualifications
      - Filters: is_system_qualification, category, is_mandatory
    - `EmployeeQualificationController`: 5 methods (index, store, show, update, destroy)
      - Manages employee-qualification assignments with certificate details
      - Checks for duplicate qualification assignments (409 Conflict)
      - Supports expiry tracking and status management (valid/expiring_soon/expired)
    - `EmployeeDocumentController`: 5 methods (index, store, show, download, destroy)
      - Document upload with validation (max 10MB, pdf/jpg/jpeg/png)
      - Storage: local disk at employees/{id}/documents/
      - File download with proper Content-Type and Content-Disposition headers
      - visible_to_employee flag for privacy control
      - Physical file deletion on destroy
    - `OnboardingController`: 7 methods (getSteps, getTemplates, getTemplate, getSubmissions, submitForm, approveSubmission, rejectSubmission)
      - Pre-contract employee onboarding workflows
      - Form template management (system + tenant-specific)
      - Submission lifecycle: draft → submitted → approved/rejected
      - HR approval/rejection with review notes
  - Created 6 API Resources for JSON transformation (no envelope wrapping):
    - `EmployeeResource`: Transforms Employee models with decrypted personal data, includes relationships
    - `QualificationResource`: Transforms Qualification models (system + custom)
    - `EmployeeQualificationResource`: Transforms pivot records with certificate details
    - `EmployeeDocumentResource`: Transforms document metadata with file information
    - `OnboardingFormTemplateResource`: Transforms templates with form_schema JSON
    - `OnboardingFormSubmissionResource`: Transforms submissions with encrypted form_data
  - Created 8 Form Request validators with comprehensive validation rules:
    - `StoreEmployeeRequest`: Validates employee creation (personal data, contract details, legal requirements)
    - `UpdateEmployeeRequest`: Validates employee updates (PATCH semantics, all fields optional)
    - `StoreQualificationRequest`: Validates custom qualification creation (7 categories)
    - `UpdateQualificationRequest`: Validates qualification updates
    - `AttachQualificationRequest`: Validates qualification attachment (certificate details, date validation)
    - `UpdateEmployeeQualificationRequest`: Validates certificate detail updates
    - `UploadEmployeeDocumentRequest`: Validates document uploads (file size, mime types, document types)
    - `SubmitOnboardingFormRequest`: Validates onboarding form submissions
  - Registered 30+ routes in /v1 namespace with tenant.inject middleware:
    - All routes protected by auth:sanctum middleware
    - Policy-based authorization (defense-in-depth with EmployeePolicy, QualificationPolicy, etc.)
    - Automatic tenant_id injection via tenant.inject middleware
  - Key Features:
    - Auto-generated unique employee numbers per tenant (format: EMP-YYYY-####)
    - Status-based workflow: pre_contract → active → on_leave/terminated
    - Onboarding validation before activation (requires onboarding_completed + contract_start_date)
    - System qualification protection (14 predefined qualifications cannot be modified via API)
    - Document visibility control for employee self-service
    - Scope-based access control for Managers (via Policies from Phase 4)
  - Test Coverage: Created 5 comprehensive test suites with 100+ test cases covering:
    - Authentication/Authorization (401/403 tests)
    - CRUD operations for all endpoints
    - Edge cases: duplicate qualifications, system qualification protection, onboarding validation, document visibility
    - Status transition validation
    - File upload/download functionality

- **Employee Management Authorization Policies & Middleware** (#322, Phase 4 of Epic #211)

  - Implemented 6 authorization policies for employee management resources:
    - `EmployeePolicy`: Scope-based authorization for employee CRUD operations
      - Admin: Full access to all employees
      - Manager: Scope-limited access via organizational unit hierarchy
      - Employee: Self-service access to own profile only
      - Methods: viewAny, view, create, update, delete, activate, terminate
    - `EmployeeDocumentPolicy`: Document visibility and access control
      - `visible_to_employee` flag for document privacy
      - Admin override for all documents
      - Manager access within organizational scope
    - `QualificationPolicy`: System vs custom qualification management
      - Prevents modification/deletion of system qualifications (14 predefined)
      - Admin-only creation and management of custom qualifications
    - `EmployeeQualificationPolicy`: Pivot table authorization
      - Admin and Manager: Full access
      - Employee: Read-only access to own qualifications
      - Scope-based access for managers
    - `OnboardingFormTemplatePolicy`: Template management authorization
      - Admin-only access to create/update/delete templates
      - Manager: Read-only access
      - Prevents modification of system templates
    - `OnboardingFormSubmissionPolicy`: Pre-contract submission control
      - Only pre-contract employees can create submissions
      - Employees can update own submissions
      - Admin and Manager: Full access within organizational scope
  - Implemented 2 middleware classes for employee status control:
    - `EnsurePreContract`: Restricts access to onboarding endpoints for
      pre-contract employees only
    - `EnsureNotPreContract`: Blocks pre-contract employees from operational
      endpoints
  - Registered all policies in `AppServiceProvider`
  - Registered middleware aliases in `bootstrap/app.php`
  - Created `EmployeeQualificationFactory` for testing
  - Added `employee()` relationship to User model
  - Comprehensive test suite: 71/71 policy tests passing (1151 total tests)
  - PHPStan level max compliant with proper null checks in all policies
  - Code formatted with Laravel Pint (PSR-12 compliant)
  - All middleware error messages internationalized (de/en)
  - Test fixes: Proper exception handling using try-catch blocks
  - Comprehensive test coverage for `EmployeePolicy` (15 tests, all passing)
    - Tests cover role-based access (Admin, Manager, Employee)
    - Organizational scope validation for managers
    - Self-service access patterns for employees
    - Status-based operations (activate, terminate)

- **Employee Management Database Schema** (#319, Phase 1 of Epic #211)

  - Created 6 new database tables for comprehensive employee management system:
    - `employees`: Core employee data with encrypted personal information (TenantKey)
    - `qualifications`: System-wide and tenant-specific qualifications (14 predefined)
    - `employee_qualifications`: Pivot table for employee-qualification relationships
      with full history tracking
    - `employee_documents`: Document management with expiry tracking
    - `onboarding_form_templates`: Hybrid onboarding (system + custom forms)
    - `onboarding_form_submissions`: Employee onboarding progress tracking
  - Implemented comprehensive status state machine for employee lifecycle:
    - `applicant` → `pre_contract` → `active` → `on_leave` → `terminated`
  - Added 14 predefined system qualifications via `QualificationsSeeder`:
    - §34a Sachkundeunterrichtung (40h) and Sachkundeprüfung (IHK)
    - IHK certifications: Servicekraft, Fachkraft, GSSK, Meister (valid §34a
      alternatives)
    - First Aid: Betrieblicher Ersthelfer (renewal: 24 months), Betriebssanitäter
      (renewal: 36 months)
    - Fire Safety: Brandschutzhelfer, Evakuierungshelfer (renewal: 36 months)
    - Safety Officer: Sicherheitsbeauftragter
    - Specialized: Diensthundeführer, Waffensachkundenachweis, Interventionsdienst
  - Health insurance tracking: type (public/private/foreign), provider, insurance
    number
  - Work/residence permit management with unlimited/limited/none types and expiry
    dates
  - All employee personal data encrypted with blind indexes for secure search
    (indexed with tenant_id for performance)
  - Support for multiple qualification paths (Unterrichtung, Prüfung,
    IHK-Ausbildungen)
  - Pre-contract onboarding workflow with JSON-based progress tracking
  - Document management with visibility control and expiry tracking
  - Full qualification renewal history tracking with `is_current` flag for active
    certifications
  - Comprehensive indexes and foreign keys for optimal query performance

- **Organizational Unit Hierarchy Validation** (#301, Part of Epic #283)

  - Added backend validation to enforce organizational hierarchy rules
  - Hierarchy ranking: Holding(1) → Company(2) → Region(3) → Branch(4) → Division(5) → Department(6) → Custom(7)
  - Child units must be **lower** in the hierarchy than their parent (child rank > parent rank)
  - **Same-level nesting is NOT allowed** (e.g., Branch under Branch is invalid)
  - Custom type (rank 7) is always valid as a child, but cannot have children
  - Root units (no parent) can be any type
  - Comprehensive test coverage with 7 hierarchy validation tests
  - Clear validation error messages with hierarchy explanation

- **Need-to-Know Organizational Unit Filtering** (#279, Part of Epic #280)
  - Implemented permission-based filtering for organizational units API endpoint
  - Users now only see organizational units they have explicit access to
  - Added `root_unit_ids` field to pagination meta for frontend tree building
  - Follows ADR-007 Need-to-Know principle: "If you don't have permission, you don't see it"
  - Added `getAccessibleOrganizationalUnits()` method to User model
  - **BREAKING CHANGE**: `/api/v1/organizational-units` response now filtered by user permissions

### Fixed

- **Newly Created Root Unit Not Visible** (#299, Part of Epic #283)

  - Root organizational units created without a parent were not visible to the creator
  - Creator now automatically receives `admin` scope with `include_descendants=true` on new root units
  - Child units continue to inherit access from parent's scope settings
  - Added 3 new tests covering auto-scope assignment and visibility

- **Organizational Unit Eager Loading** (#282, Part of Epic #280)

  - Added missing `parent()` relation to `OrganizationalUnit` model for eager loading support
  - Fixes N+1 query issues when loading organizational units with parent relationships

- **PWA Session Restoration After Long Inactivity** (#271)

  - Added `RestoreSessionFromRememberToken` middleware to restore sessions from remember token
  - Fixes 401 logout when accessing protected endpoints after hours of inactivity
  - Laravel's remember token functionality only works with SessionGuard on web routes,
    not with Sanctum SPA authentication - this middleware bridges that gap
  - Middleware runs after `EnsureFrontendRequestsAreStateful` to ensure session availability
  - Users now stay logged in as long as the remember cookie is valid (typically weeks)
  - Added tests verifying middleware registration and behavior

- **PWA Session Expiry Handling** (#270)

  - SPA login now uses `remember: true` for long-lived sessions (PWA requirement)
  - Users stay logged in until explicit logout instead of 120-minute session timeout
  - Works via Laravel's remember_token cookie for automatic session extension
  - Added tests verifying remember token is set on SPA login

- **Unauthenticated API Request Handling** (#253)
  - Fixed 500 "Route [login] not defined" error for unauthenticated requests to protected endpoints
  - API now returns proper 401 JSON response `{"message": "Unauthenticated."}` instead of attempting redirect
  - Added exception handler for `AuthenticationException` in `bootstrap/app.php`
  - Added tests for unauthenticated request scenarios

### Added

- **Deployment Documentation** (#245)

  - `docs/deployment.md`: Complete production deployment guide with prerequisites, environment setup, KEK generation, database setup, tenant key initialization, health checks, and troubleshooting
  - `docs/deployment-checklist.md`: Quick reference checklist for deployment operators with step-by-step verification
  - `docs/deployment-uberspace.md`: Uberspace shared hosting specific deployment guide with platform-specific commands and service configuration
  - README.md updated with deployment section highlighting key differences from development setup
  - Part of Epic: Application Setup & Health Check System (SecPal/api#241)

- **Tenant Key Setup Command** (#244)

  - `php artisan tenant:setup` command for guided tenant key initialization during new deployments
  - Interactive validation of KEK file existence and secure permissions (0600)
  - Idempotent design prevents duplicate tenant key creation
  - Comprehensive error handling with actionable error messages
  - Security-first approach: no plaintext keys logged, permission warnings
  - Part of Epic: Application Setup & Health Check System (SecPal/api#241)

- **Setup Validation Command** (#243)

  - `php artisan app:validate-setup` command for deployment readiness checks
  - Validates database connectivity, tenant keys, KEK file, storage permissions, and PHP extensions
  - Colored console output with ✅/❌ indicators and actionable error messages
  - Exit code 0 (success) or 1 (failure) for CI/CD integration
  - Part of Epic: Application Setup & Health Check System (SecPal/api#241)

- **Health Check Endpoints** (#242)

  - `/health/live` endpoint for liveness probes (minimal process check)
  - `/health/ready` endpoint for readiness probes (database, tenant keys, KEK file)
  - Kubernetes-compatible response format with 200 OK (ready) or 503 Service Unavailable (not ready)
  - Part of Epic: Application Setup & Health Check System (SecPal/api#241)

- **Production Deployment Guide** (#219)

  - Complete production deployment checklist with security requirements
  - Nginx and Apache configuration examples with TLS/SSL
  - Rate limiting configuration for login and API endpoints
  - Environment variable templates for production
  - Client configuration for both httpOnly cookies (Web/PWA) and Bearer tokens (Native apps)
  - Health check endpoint and monitoring setup
  - Backup and rollback procedures
  - Security incident response guidelines
  - Part of Epic: httpOnly Cookie Authentication Migration (SecPal/api#217)

- **Integration Tests for Sanctum Authentication** (#219)

  - 8 comprehensive integration tests in `tests/Feature/Auth/SanctumIntegrationTest.php`
  - CORS credentials and preflight request testing
  - Session performance and concurrent device tests
  - Token size and session configuration validation
  - Hybrid authentication support (Cookie + Bearer token)
  - Part of Epic: httpOnly Cookie Authentication Migration (SecPal/api#217)

- **Sanctum SPA Authentication Guide** (#218)

  - Comprehensive documentation for httpOnly cookie authentication
  - Architecture diagrams and authentication flow
  - Configuration guide for both development and production
  - Troubleshooting section with common issues and solutions
  - API endpoint examples with curl commands
  - Frontend integration code samples (TypeScript)
  - Security best practices and production deployment checklist
  - Part of Epic: httpOnly Cookie Authentication Migration (SecPal/api#217, SecPal/frontend#205)

- **httpOnly Cookie Authentication Tests & Documentation** (#208)

  - Comprehensive test suite in `tests/Feature/Auth/SanctumCookieAuthTest.php`
  - 14 integration tests covering Sanctum authentication configuration
  - Tests verify session cookie configuration (httpOnly, secure, sameSite=lax)
  - Tests cover login flow, Bearer token logout, authenticated requests via actingAs(), and personal access token management
  - Tests validate both SPA (cookie) and API client (Bearer token) authentication modes
  - Complete API documentation in `docs/api/authentication.md`
  - Detailed httpOnly cookie authentication flow with step-by-step examples
  - CSRF token handling guide with JavaScript examples
  - Migration guide from localStorage to httpOnly cookies
  - Security recommendations for SPA and API client developers
  - Production deployment checklist for secure cookie configuration
  - Part of Epic: httpOnly Cookie Authentication Migration (frontend#208)
  - Closes: #208

- **httpOnly Cookie Authentication** (#210)
  - Configured Laravel Sanctum for httpOnly cookie-based SPA authentication
  - Session cookies configured with `httpOnly=true`, `sameSite=lax` for CSRF protection
  - `SESSION_SECURE_COOKIE` environment variable for HTTPS enforcement in production
  - CSRF token endpoint accessible at `/sanctum/csrf-cookie` for SPA requests
  - CORS configuration updated to allow credentials from frontend domains
  - Security headers middleware added globally (X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy)
  - HSTS (Strict-Transport-Security) header enabled in production only
  - 8 comprehensive CSRF protection tests
  - 6 comprehensive security headers tests
  - All session and CSRF configuration verified via automated tests
  - Part of Epic: httpOnly Cookie Authentication Migration (frontend#208)

### Fixed

- **SecretController**: Removed hardcoded tenant ID resolution (#190)
  - Replaced `TenantKey::first()` workaround with proper `InjectTenantId` middleware
  - New middleware automatically injects `tenant_id` into request for Secret routes
  - Single-tenant development mode: Uses first available TenantKey
  - Production-ready pattern: Middleware can be extended for user-based tenant resolution
  - Middleware registered as `tenant.inject` alias in `bootstrap/app.php`
  - Applied to all `/v1/secrets` and `/v1/attachments` routes
  - 5 comprehensive middleware tests added
  - Resolves TODO comment in `SecretController::store()`
  - Maintains backward compatibility: Respects pre-existing `tenant_id` in request
- **CI/CD**: Codecov upload now fully functional for Dependabot PRs
  - Added `continue-on-error` for dependabot/renovate bots to prevent blocking
  - Made `CODECOV_TOKEN` optional (tokenless uploads work for public repos)
  - Upload step succeeds even without token access (Dependabot security restriction)
  - Normal PRs still fail CI on codecov errors (security preserved)
  - Aligns with frontend implementation (DRY principle)
  - Fixes issue where Codecov checks remained pending/missing on Dependabot PRs
- **DDEV**: Test database creation now fully automated via post-start hook
  - Automatically creates `testing`, `testing_test_1`, `testing_test_2` on every `ddev start`
  - Eliminates "database does not exist" errors during parallel test execution
  - Idempotent: checks existence before creation, safe to run repeatedly
  - Root cause fix replacing previous manual workarounds

### Added

- **User Language Preference** (#86)

  - New `preferred_locale` column in `users` table (VARCHAR(5), nullable)
  - PATCH `/v1/me/language` endpoint to update user's preferred language
  - Supports `en` (English) and `de` (German)
  - Can be set to `null` to use default/Accept-Language header
  - Form request validation via `UpdateUserLanguageRequest`
  - 8 comprehensive feature tests
  - Database migration: `2025_11_16_192506_add_preferred_locale_to_users_table`

- **Secret Sharing & Access Control (Phase 3)** (#182) - **COMPLETED 19.11.2025**

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
  - **Attachment Permissions**: SecretAttachment authorization extended
    - Updated `SecretAttachmentPolicy` to honor share-based permissions
    - viewAny/view: Owner OR read+ permission (read/write/admin)
    - create/delete: Owner OR write+ permission (write/admin)
    - Removed TODO comment, integrated with `Secret::userHasPermission()`
  - **Integration Tests**: Comprehensive end-to-end validation (#189)
    - 20 integration tests covering Secrets + Shares + Attachments workflows
    - Tests: Permission levels (read/write/admin), expiration, revocation
    - Tests: Attachment upload/download with share-based access
    - Tests: Role-based sharing, role removal, multiple roles
    - Tests: Cascade deletes, owner always-access, self-sharing edge cases
    - All tests passing with 42 assertions
  - **Developer Documentation**:
    - Secret Sharing Guide: docs/guides/secret-sharing.md (created)
    - CHANGELOG: Phase 3 completion documented
  - **Database Foundation** (already merged):
    - `secret_shares` table with XOR constraint
    - `SecretShare` model with relationships and scopes
    - Migration tests and model tests (13 total)
  - **Total Test Coverage**: 22 Controller tests (Secrets), 18 Controller tests (Shares),
    20 Integration tests, 13 Model tests = 73 tests, all passing
  - **Note**: Tenant resolution uses temporary `TenantKey::first()` pattern (TODO: TenantMiddleware)
  - **Delivered**: 4 merged PRs (#183, #184, #185, #191) + Issue #189 completion
  - **Status**: Phase 3 100% complete, ready for frontend implementation

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
