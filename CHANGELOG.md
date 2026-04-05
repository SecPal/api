<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- added a policy-protected admin MFA reset path at `DELETE /v1/users/{user}/mfa`, dedicated `users.reset_mfa` permission seeding, explicit MFA reset throttling, and authentication audit entries for MFA enable/disable, recovery-code regeneration, recovery-code depletion, and admin-triggered resets
- Added the MFA phase-1 backend foundation by integrating `laragear/two-factor`, publishing a UUID-safe `two_factor_authentications` migration, wiring the `User` model into the package contract, and covering enrollment, recovery-code rotation, and disablement lifecycle behavior with focused tests
- Added the phase-1 MFA API endpoints for login challenges, TOTP enrollment confirmation, `/me/mfa` status, recovery-code regeneration, and authenticated MFA disablement so the frontend can build against real SecPal API behavior instead of only the contract
- security audit document (`SECURITY_AUDIT_API_VALIDATION.md`) covering API validation, error handling, and request semantics with 3 HIGH, 6 MEDIUM, 5 LOW findings and 3 best-practice recommendations; includes prioritized fix order and negative test ideas

### Changed

- Replaced the API Translation.io workflow with repo-native Polyglot-managed PO/Gettext catalogs, added a dedicated production blocker for the Polyglot web UI, and moved translated mail key subjects into checked-in language files so API translation maintenance now stays local and POedit-friendly.
- clarified the repo-local branch-start and post-merge readiness workflow so new API work must start from a clean, updated local `main`, and post-merge cleanup now explicitly returns the repo to `main`, refreshes dependencies where applicable, runs a suitable readiness command, and confirms a clean working tree

### Fixed

- restored explicit repo-local Copilot governance by making TDD-first, quality-first, one-topic-per-PR, immediate issue creation for out-of-scope findings, and EPIC-plus-sub-issue requirements always-on again; the API runtime overlay now auto-loads repo-wide so these rules remain present while working
- clarified the repo-local PR workflow so finished API work must be self-reviewed, committed, and pushed before any PR exists, and the first PR state must always be draft until the final PR-view self-review is clean
- exposed `emailVerified` consistently in auth payloads, taught the seeded local Test User to remain verified on repeat seed runs, and unblocked the frontend from handling unverified accounts with a dedicated gate instead of surfacing raw route-level verification errors deep inside the app
- added explicit `/v1/employees/{employee}/leave` and `/v1/employees/{employee}/return-from-leave` transitions that snapshot the employee's prior runtime role/direct-permission state, reduce on-leave access to a seeded read-only baseline, and restore the prior access model atomically when the employee returns; termination now also clears direct permissions so runtime access is fully revoked
- encrypted employee phone storage at rest by moving the field to `phone_enc` plus tenant-scoped `phone_idx`, backfilling existing rows in a migration, and keeping the public API field name `phone` unchanged while documenting why employee and user email still remain plaintext for auth-critical lookups
- moved employee activation and termination side effects into an explicit lifecycle service used by the employee controller and scheduled status-update command, so status changes now run inside a single transaction for role assignment or access revocation instead of relying on hidden observer-driven account mutations
- issued Sanctum Bearer tokens from `POST /v1/auth/token` with the explicit `api-access` ability and enforced that ability across `auth:sanctum` protected API routes, so compromised or manually under-scoped tokens can no longer reach the authenticated API surface unless they carry the intended SecPal access scope
- extended `/health/ready` with cache-backed scheduler and queue-worker heartbeat checks, so the API now reports stale background processing only when the scheduler stops pulsing or when pending `default` / forensic queue jobs no longer have a fresh worker heartbeat
- removed the dead `SiteController::costCenters()` fallback and its stale TODO now that `GET /v1/sites/{site}/cost-centers` is served by the dedicated `CostCenterController` with `CostCenterResource`, so the API no longer carries an unused manual-pagination implementation beside the real endpoint path
- precomputed customer/site update visibility for `/v1/me/customer-assignments` and `/v1/me/site-assignments` so nested `Api/V1` customer/site resources stop triggering per-record policy queries during collection serialization, with regression coverage that keeps the assignment response query count bounded
- enabled Laravel email verification on the `User` model, added signed verify/resend endpoints, enforced `verified` middleware on protected non-onboarding application routes, and now send a verification notification when onboarding completes so unverified accounts cannot immediately use the authenticated app surface
- aligned the nested Api/V1 customer and site assignment payloads with the OpenAPI contract so `/v1/me/customer-assignments` and `/v1/me/site-assignments` now return the documented customer/site fields instead of truncated nested resource objects; standardized all Api/V1 resource timestamps to `toIso8601String()` for a consistent date-time format across the layer
- replaced the onboarding form template factory's implicit `TenantKey::first()` fallback with an explicit `TenantKey::factory()` default so onboarding tests no longer depend on pre-seeded tenant state
- replaced the remaining `@example.com` onboarding test fixtures with `@secpal.dev` so the onboarding suites follow the repository domain policy
- removed the unused `check.customer.scope` middleware alias from bootstrap registration so the API no longer advertises a non-existent custom middleware
- validated employee document uploads by MIME type as well as file extension, so renamed plain-text files no longer pass PDF/JPEG/PNG checks
- aligned nested customer site listings with site visibility rules and documented `type` / `is_active` filters
- enforced authenticated tenant membership in the `tenant` middleware so `/v1/tenants/{tenant}/...` routes now reject cross-tenant access attempts with `403 Forbidden`
- narrowed onboarding throttle keys to distinct validate-vs-complete scopes plus client IP, and normalized invitee email when present, so repeated failures for one onboarding link no longer rate-limit unrelated invitees or share a bucket with the separate completion step behind the same IP
- made successful password resets revoke all active Sanctum tokens, clear remember-me state, delete persisted sessions, and emit a dedicated authentication audit event so compromised access cannot survive a credential reset
- hardened `TenantKey::generateKek()` against permissive process umask values so new KEK files are created with `0600` from the first write instead of only being tightened after creation
- added a dedicated `keys:generate-kek` bootstrap command, made `keys:generate-tenant` fail fast with explicit KEK setup guidance, and aligned the API setup guide with the real `storage/app/keys` default plus `KEK_PATH` override support
- stopped onboarding token validation from loading every active token into memory by storing an indexed deterministic lookup hash for new tokens and self-healing legacy rows on first successful use
- replaced the remaining `@example.com` addresses in `tests/Feature/AuthTest.php` with `@secpal.dev` so the auth regression suite follows the repository domain policy for test data
- rejected oversized `per_page` values on `GET /v1/customers` during request validation so abusive list sizes now fail fast with `422 Unprocessable Entity` instead of reaching the customer collection query path
- scoped Customer-request permission checks to the active tenant and active permission windows, and added dedicated authorization lookup indexes so invalid Customer reads/writes stop dragging through unrelated role/direct-permission rows before the request is rejected
- made `POST /v1/auth/logout-all` revoke all bearer tokens, clear persisted sessions and remember-me state, and emit a dedicated authentication audit event so bulk sign-out fully matches the security expectation; corrected an operator-precedence bug that prevented the SPA session-invalidation branch from ever running, and cleared both `AuthManager::$guards` and the `auth.driver` IoC singleton after SPA logout so that `DatabaseSessionHandler` cannot write the departing user's ID into the new anonymous session row
- stopped isolated API worktree test runs from emitting missing-`.env` phpdotenv warnings by creating a temporary bootstrap stub only for the lifetime of the test process when no local environment file exists
- made `tenant:setup` fail fast with a targeted unreadable-KEK error instead of falling through to the generic invalid-file path when the current process cannot read the KEK file
- equalized the password reset request response floor with a minimum configurable delay so existing-account and missing-account paths are harder to distinguish via timing measurements
- sanitized employee document filenames before persisting them and before emitting `Content-Disposition`, so uploaded names can no longer inject or reshape download headers
- aligned password resets with the onboarding password policy by defining a shared strong `Password::defaults()` baseline and reusing it in `PasswordResetRequest`, so reset flows no longer accept weaker passwords than initial account setup
- enabled encrypted session payloads by default via `SESSION_ENCRYPT=true`, updated the deployment and Sanctum SPA guides to match, and added regression coverage around the secure session-encryption default and config fallback so session-config behavior reflects production intent
- hardened `.gitignore` so `.key` files are ignored both directly under `storage/` and in nested storage subdirectories, reducing the chance that key material is committed from deeper runtime paths
- enforced `0600` KEK file permissions at runtime by making `TenantKey::loadKek()` reject insecure key files and aligning `tenant:setup` plus `app:validate-setup` to fail fast on permissive KEK modes
- validated the `email` query value on `GET /v1/tenants/{tenant}/persons/by-email` so malformed addresses now fail fast with `422` instead of reaching blind-index lookup logic
- added the HSTS `preload` directive to the API security-header baseline so secure clients can enforce HTTPS from first contact once the domain is enrolled in browser preload lists
- added `Cache-Control: no-store, no-cache, must-revalidate, private` and `Pragma: no-cache` to the API security-header baseline so sensitive responses are less likely to persist in browser or intermediary caches
- configured Sanctum personal access tokens to expire after `1440` minutes by default, documented `SANCTUM_TOKEN_EXPIRY_MINUTES` in `.env.example`, and added regression coverage that expired bearer tokens are rejected with `401 Unauthorized`
- aligned `.env.example` with the production session default by documenting `SESSION_DRIVER=database`, so fresh deployments no longer advertise a cookie-session configuration that the runtime config does not actually default to
- prefixed newly issued Sanctum bearer tokens with `sec_` by default and documented `SANCTUM_TOKEN_PREFIX=sec_` in `.env.example` so committed API tokens are easier for secret-scanning tooling to detect
- hardened organizational scope rank validation so create and partial update requests cannot combine guards-only rank filters with leadership ranges or introduce leadership ranges without a positive minimum bound
- removed the stale Request-layer PHPStan ignore that no longer matched any real findings during preflight
- rejected direct `status` writes on `PATCH /v1/employees/{employee}` so employee lifecycle transitions can no longer bypass the dedicated activate and terminate endpoints and their business-rule checks
- switched API CI Prettier check to a local reusable workflow (`reusable-prettier.yml`) because GitHub Actions cannot resolve the shared `.github` composite action via `SecPal/.github/.github/actions/` due to the `.github/.github/` path ambiguity when the repository itself is named `.github`; the local workflow preserves the `Formatting Check / Check Code Formatting` check name required by branch protection
- returned `chain_link_valid` from `GET /v1/activity-logs/{activity}/verify` so the Activity Log detail dialog no longer leaves the hash-link verification dot stuck in pending when the chain has already been processed successfully; corrected genesis validation in `Activity::verifyChainLink()` to check all earlier tenant activities (not just same `log_name`) so the signal stays accurate when the tenant hash chain spans multiple log names
- updated `Activity::casts()` return type annotation from `array<string, string>` to `array<string, string|\Stringable>` to match `laravel/framework` 13.3.0, which expanded the allowed cast values to include `Stringable` objects

### Changed

- aligned the repo-local domain policy and validation script with the renamed Android application identifier `app.secpal`, removing the old identifier-only exception from current governance text
- aligned nested collection semantics outside the onboarding flow so official admin surfaces consistently use `403` for missing authorization, reserve `404` for unsupported, missing, or tenant-hidden resources, and keep `200` with an empty collection only for callers who are entitled to open that collection; `GET /v1/sites/{site}/cost-centers` now also requires visibility of the parent site before returning cost center data
- updated active API development and operations guides to use the current native PHP workflow instead of stale DDEV-first command examples, and marked older DDEV-era retrospectives as historical context where those references are intentionally retained
- updated `docs/MAIL_SYSTEM.md` and `docs/PRODUCTION_TEST_PHASE2_EMAIL.md` to reflect the current direct-server Mailpit setup (`127.0.0.1:1025` SMTP, `127.0.0.1:8025` UI) instead of stale DDEV-routed instructions
- centralized the official employee status set to `applicant`, `pre_contract`, `active`, `on_leave`, and `terminated`, reused that definition across employee create/update/list validation, clarified in API messages that onboarding invitations are only allowed while status is `pre_contract`, and documented the same admin-facing rule set in `README.md`
- clarified the official auth/self-service surface so browser SPAs use `POST /v1/auth/login`, Android/native/API clients use `POST /v1/auth/token`, `GET /v1/me` remains the canonical self-service read endpoint, and `POST /v1/auth/logout` now cleanly logs out both session- and token-authenticated clients while `POST /v1/auth/session/logout` is retained only as a documented legacy alias
- documented the auth-path decision matrix, the intentional `400` vs `422` login semantics, and the main regression hotspots around Sanctum stateful middleware, browser-context detection, CSRF boundaries, and shared logout behavior so future auth changes do not accidentally re-mix session and token login paths
- added regression coverage and API guide clarifications that keep guessed aliases such as `GET /v1/auth/me`, `GET /v1/user`, `GET /v1/user/profile`, and `GET /v1/profile` intentionally unsupported while documenting `GET /v1/me` as the canonical self-service endpoint
- aligned token-login responses with session-login responses by returning the same authorization context (`roles`, `permissions`, `hasOrganizationalScopes`) inside the `user` payload for Android/native/API clients
- corrected API guides and deployment docs that previously mixed up SPA and token login flows or referenced stale `/api/v1/*`, `/v1/users/me`, and similar non-existent auth/self-service paths

- `.github/copilot-instructions.md` now requires a branch hygiene check before any write action so API work never starts on local `main` and dirty non-`main` branches must be assessed before continuing
- `.github/copilot-instructions.md` now requires stale `SPDX-FileCopyrightText` years in edited files and license sidecars to be normalized to `YYYY` or `YYYY-YYYY` without spaces
- `.github/copilot-instructions.md` now clarifies that if an edited file has no inline SPDX header, its companion `.license` file must be checked and updated instead
- repo-local API instructions and overlays now also restate Copilot review handling, signed-commit checks, EPIC/sub-issue requirements, REUSE checks, 4-pass review, and the `secpal.app` vs `secpal.dev` use-case split so project-wide governance is locally complete
- repo-local API instructions and overlays now also require warning, audit, and deprecation notices from scripts and package managers to be reviewed and either fixed or tracked immediately
- `.github/copilot-instructions.md`, `.github/instructions/org-shared.instructions.md`, and `.github/instructions/php-laravel.instructions.md` now describe the API runtime as a native PHP environment for local shells and remote SSH sessions on the VPS instead of requiring DDEV wrappers
- `.github/copilot-instructions.md` now defines SPDX header maintenance explicitly: edited files with older copyright years should be updated to a year range ending in the current year

### Removed

- Removed the deleted legacy product module from the API, including its retired
  CRUD endpoints, sharing flows, attachment handling, backing database tables,
  and obsolete migrations in 0.x.
- Removed the unused built-in Laravel `/up` health route so the API exposes only the documented `/health`, `/health/live`, and `/health/ready` endpoints.
- Removed the outdated `docs/BINARY_ENCODING_ISSUE.md` WIP debugging note because the current base64-on-VARCHAR binary storage and blind-index implementation already supersedes the old PostgreSQL `BYTEA` investigation.

### Added

- `scripts/check-live-cors-health.sh` and `.github/workflows/live-cors-smoke.yml` - automated live smoke coverage for `GET /health` and `OPTIONS /health` CORS behavior on `api.secpal.dev` against the first-party `https://app.secpal.dev` origin
- `app/Services/EmployeeDocumentStorageService.php` - encrypted-at-rest storage service for employee document uploads and downloads
- persistent employee onboarding workflow state on API resources and onboarding transitions, including bootstrap completion, resumable draft progress, HR change requests, and resubmission after rejected forms so the pre-contract compliance flow has an explicit backend state foundation instead of relying on implicit lifecycle inference

- `.github/instructions/php-laravel.instructions.md` - targeted Laravel, Pest, and native PHP runtime guidance for PHP work in this repo
- `.github/instructions/github-workflows.instructions.md` - targeted workflow and Dependabot guidance for GitHub automation files in this repo
- `.github/instructions/org-shared.instructions.md` — org-wide Copilot principles (TDD, quality gates, PR protocol, GDPR conventions) auto-loaded for all files in this repo via `applyTo: "**"`

### Fixed

- isolate the flaky OpenTimestamp-related Merkle batch tests from real OTS submissions by scoping queue fakes to `SubmitMerkleRootToOpenTimestamp` and stubbing `OpenTimestampService` in the affected test suites, so CI no longer depends on external calendar network timing
- make `ots:check` fail fast when `python3`, `ots`, the `opentimestamps` Python module, or the required helper scripts are missing, prefer `pip3` over `pip` for update checks with a clear fallback, and cover the stricter runtime validation paths in the OpenTimestamp console command tests
- surface invitation eligibility directly in employee API resources and return a clearer `send_invitation` validation error when clients request onboarding for any non-`pre_contract` employee status
- split onboarding token validation and completion into separate rate-limit buckets and count only business-level failures toward those buckets, so repeated successful link opens, reloads, and normal onboarding form corrections no longer burn through the same throttle state and valid onboarding links stop flipping into premature `429 Too many onboarding attempts`
- reject stateless misuse of `POST /v1/auth/login` before controller execution so the browser/session-only endpoint now returns a controlled JSON `400` directing API clients to `POST /v1/auth/token` instead of throwing `500 Session store not set on request`, while preserving the normal Sanctum/CSRF flow for real SPA logins
- make `POST /v1/auth/logout` follow the auth mechanism Sanctum actually resolved instead of the mere presence of an `Authorization` header, and add regression coverage for mixed session-plus-header requests so accidental header leakage cannot silently switch browser logout into the token branch
- restore compatibility with `spatie/laravel-activitylog` `5.0.0` by migrating runtime usage to the v5 namespaces and config keys, switching activity-change assertions to `attribute_changes`, adding the required `activity_log.attribute_changes` schema column, extending the hash-chain payload (`ProcessActivityHashChain` job and `verifyChain()`) to include `event` and `attribute_changes` so tampering with change data is detectable, and removing the `Schema::hasColumn()` migration guards so schema drift fails loudly
- move protected endpoint authorization into Form Request `authorize()` checks where controller-level policy checks previously happened too late, so unauthorized users now receive `403` before payload validation on affected site, employee, assignment, document, organizational-unit, qualification, customer, and scope endpoints
- harden employee and site list filters to fail closed without leaking foreign-tenant resource existence, so cross-tenant UUID filters now return authorized empty result sets instead of tenant-scoped validation disclosures
- make `TenantKey::generateKek()` tolerate parallel KEK-directory creation and cover the missing-directory path so the API stays stable on the `laravel/sail` `1.55.0` Dependabot PR test matrix
- tighten `BuildMerkleTreeBatch` Merkle proof return typing and add regression coverage so the API stays PHPStan-clean after the `phpstan/phpstan` `2.1.43` Dependabot update
- couple `send_invitation` on `POST /v1/employees` to an explicit onboarding invitation service, generate the onboarding token before mail delivery, persist invitation delivery state on the employee record, and surface partial delivery failures in the API response instead of silently relying on the observer queue path
- align employee creation validation with the frontend-required minimum dataset so `date_of_birth`, `position`, `contract_start_date`, and `organizational_unit_id` are now mandatory on `POST /v1/employees`
- document the `EnforcesTenantRouteBinding` controller invariant so route-bound single-record actions can rely on the concern instead of re-checking tenant ownership manually
- stabilize `UserPermissionAssignmentApiTest` by creating same-tenant users explicitly instead of relying on the `UserFactory` fallback tenant lookup when the fixture creates multiple tenants
- remove the redundant `UserPermissionController` tenant-ownership re-checks now that route-bound `User` models already fail closed through `EnforcesTenantRouteBinding`
- document the only explicit global-record exceptions to fail-closed tenant route binding so system qualifications and onboarding form templates are called out alongside the binding concern itself
- harden API CORS origin matching for `/v1/*`, `/health*`, and Sanctum endpoints by replacing the single-origin static fallback with exact origin pattern matching, so only explicitly allowed origins receive `Access-Control-Allow-Origin` and `Access-Control-Allow-Credentials`
- return a stable JSON `404` payload for API `ModelNotFoundException` responses so missing resources no longer expose Laravel model class names or framework-internal not-found text
- preserve Laravel's UUID-aware implicit route model binding for tenant-scoped resources so invalid detail IDs return a controlled `404 Not Found` instead of bubbling PostgreSQL UUID syntax errors into `500` responses on employee, customer, site, and organizational-unit endpoints
- validate UUID filter parameters on employee and site index endpoints and extend regression coverage across qualification, employee-qualification, and cost-center UUID detail routes plus activity-log not-found handling so invalid or unknown identifiers now fail with controlled `422` or `404` responses instead of database-level errors
- move employee and site list-filter validation into dedicated Form Request classes and reject foreign-tenant UUID filter values with controlled `422` validation responses
- cover the base `/health` endpoint with Laravel CORS handling so it returns the same CORS and preflight headers as `/health/live` and `/health/ready`, and remove the mistaken legacy SPA defaults from the API config examples
- align the API authentication and deployment guides with the active `app.secpal.dev` SPA domain and `api.secpal.dev` API host by removing stale `.app` frontend and API example domains
- render a branded SecPal HTML 404 page for browser requests while preserving JSON 404 responses for API clients
- add matching custom SecPal error pages for 403, 500, and 503 responses using the same simplified Tailwind-inspired layout as the 404 page
- normalize onboarding template ID collections and site full-address filtering so the API stays PHPStan-clean after the `phpstan/phpstan` `2.1.41` Dependabot update
- Removed a stale legacy schema comment from the employee migration so the API source no longer references the deleted module outside historical changelog entries
- bootstrap PostgreSQL test databases automatically during Pest/PHPUnit startup so local `php artisan test` runs work without relying on DDEV hooks
- aligned SPA auth and CORS defaults plus affected feature tests with the VPS-based `app.secpal.dev` and `api.secpal.dev` development domains instead of legacy localhost/Vite assumptions
- stabilize local and CI test bootstrapping by forcing `APP_ENV=testing` under PHPUnit, isolating the OpenTimestamp monitor test from the real health check runtime, and validating KEK permissions against the per-test key path
- install `opentimestamps-client` in the DDEV web image so OpenTimestamp-backed Laravel tests and local stamping commands do not fail with missing Python module errors
- reject cross-tenant target users on role-assignment and direct-permission administration endpoints with fail-closed 404 responses and matching policy checks
- constrain route model binding for tenant-owned admin models to the authenticated tenant so cross-tenant resource identifiers fail closed before controller logic
- extend `EnforcesTenantRouteBinding` test coverage to include bool, null, and non-scalar invalid route key values so all three `match` arms in the UUID-rejection path are exercised
- scope the `AuthenticationException` JSON renderer to API and JSON requests so non-API browser requests can fall back to standard HTML error handling instead of always receiving a JSON 401
- update `index` action docblocks in `EmployeeController` and `SiteController` to reflect the actual `/v1/*` route paths rather than the stale `/api/v1/*` prefix
- assert the stable `{ "message": "Resource not found." }` JSON payload in the `QualificationController`, `EmployeeQualificationController`, `CostCenterController`, and `ActivityLogController` not-found regression tests so the 404 contract is fully enforced across all affected endpoints
- scope nested route bindings for cost centers and employee documents so child resources fail closed when resolved through the wrong parent path
- validate assignment target users and employee qualification references against the active tenant so foreign-tenant IDs fail with 422 while global system qualifications remain attachable
- preserve access to global system qualifications and onboarding templates while still fail-closing tenant-foreign bindings, including employee-linked submissions and qualification records
- extend fail-closed tenant route-binding regression coverage for employee and organizational-unit models and prove cross-tenant employee detail requests return 404 before controller logic runs
- apply fail-closed tenant route binding to the Person model and extend customer, site, and person regression coverage so foreign-tenant bindings now fail closed consistently across the remaining top-level tenant-owned models
- harden OpenTimestamp proof handling by creating restrictive temporary proof files via shared cleanup helpers and by logging only sanitized digest hints instead of full digests
- align the Person API contract with plaintext transient fields by accepting `note_plain` instead of exposing direct `*_enc` input semantics
- block tenant-crossing user reuse during pre-contract employee provisioning so onboarding accounts are only linked within the same tenant, and avoid logging employee email addresses in this path

### Changed

- upgraded the API runtime baseline to Laravel 13-compatible dependency constraints, refreshed direct supporting dependencies, aligned Sanctum's CSRF middleware reference with Laravel 13, and aligned cache configuration with Laravel 13's `serializable_classes` hardening default
- updated repo-local API documentation and automation metadata so current framework references consistently point to Laravel 13 instead of stale Laravel 12 text; updated doc links in `docs/rbac-architecture.md`, `docs/MAIL_SYSTEM.md`, and `docs/QUEUE_WORKERS.md` from `12.x` to `13.x`; removed version-specific qualifier from `ActivityLogIntegrationTest` workaround comment
- `.env.example` — `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` no longer include
  `localhost` or `127.0.0.1` entries. Local development setups that use the Vite
  dev server on `localhost:5173` must add the relevant origins to their local `.env` file.
- `pint.json` and repository-wide PHP/test files - re-enabled `fully_qualified_strict_types` after a dedicated Pint baseline to keep the stricter style rule active without mixing it into the dependency bump PR
- `app/Http/Controllers/Api/V1/EmployeeDocumentController.php`, `app/Policies/EmployeeDocumentPolicy.php`, and `app/Http/Resources/EmployeeDocumentResource.php` - aligned employee document authorization with a consistent policy model, encrypted stored document binaries at rest, and removed raw storage paths from API responses
- `database/factories/EmployeeDocumentFactory.php` and employee document feature/policy tests - updated fixtures and tests to reflect encrypted storage and the normalized authorization behavior
- `app/Services/EmployeeDocumentStorageService.php` and employee document controller/policy tests - tightened missing-file handling to return 404 only for true storage misses, validated upload metadata before persisting encrypted blobs, and replaced duplicate manager coverage with a real scoped-manager scenario
- `pint.json` - disabled `fully_qualified_strict_types` to keep repository-wide formatting checks stable after upgrading `laravel/pint` to `v1.28.0`

- `.github/copilot-instructions.md` - replaced comment-based pseudo-inheritance and oversized repo guidance with a self-contained runtime baseline for this repository
- `.github/instructions/org-shared.instructions.md` - reduced to a short repo-local overlay that reinforces the runtime baseline instead of duplicating org documents

### Historical Migration Notes

- **Test Framework Migration - COMPLETE** (Issues #491, #500, #441)
  - **COMPLETED** All PHPUnit tests converted to Pest syntax (8 of 8 files)
  - **Files Converted**: 8 test files (48 tests total)
    - `tests/Feature/Jobs/SubmitMerkleRootToOpenTimestampTest.php` (7 tests) - Issue #491
    - `tests/Feature/Jobs/UpgradeOpenTimestampProofsTest.php` (9 tests) - Issue #491
    - `tests/Feature/Console/Commands/MonitorOpenTimestampTest.php` (4 tests) - Issue #491
    - `tests/Feature/Console/Commands/UpdateOpenTimestampTest.php` (5 tests) - Issue #491
    - `tests/Feature/Console/Commands/CheckOpenTimestampStatusTest.php` (4 tests) - Issue #500
    - `tests/Unit/Services/ActivityLogServiceLoginFailureTest.php` (5 tests) - Issue #500
    - `tests/Unit/Services/OpenTimestampProofMergingTest.php` (4 tests) - Issue #500
    - `tests/Unit/Jobs/BuildMerkleTreeBatchRefactoringTest.php` (10 tests) - Complete migration
  - **Changes Applied**:
    - Removed `class XTest extends TestCase` declarations
    - Converted `public function test_*()` to `test('description', function() { ... })`
    - Converted `setUp()` to `beforeEach(function() { ... })`
    - Replaced `use RefreshDatabase` trait with `uses(RefreshDatabase::class)`
    - Added proper `use function Pest\Laravel\artisan` imports
    - Updated SPDX headers (// to /\*\* \*/ style)
    - Improved test descriptions (snake_case to natural language)
    - Converted helper methods to standalone functions (e.g., `buildCalendarProof()`)
  - **Quality Assurance**:
    - ✅ All 48 converted tests passing
    - ✅ PHPStan Level 9 clean (no errors)
    - ✅ Laravel Pint compliant
    - ✅ **NO PHPUnit-style classes remaining in entire test suite**
  - **Benefits**: Improved test readability, consistency with project-wide testing standards, complete Pest migration achieved

### Added

- **Onboarding Completion Detection System** (Issue #498, Epic #469)
  - **IMPLEMENTED** Automatic detection and tracking of onboarding completion
  - **`OnboardingCompletionService`**: Service class for completion logic
    - `checkCompletion(Employee)`: Detects when all required templates approved, auto-updates `employee.onboarding_completed` and `employee.onboarding_completed_at`
    - `getCompletionStatus(Employee)`: Returns detailed status (is_completed, total_required, completed_required, missing_templates)
  - **Completion Criteria**: All templates with `is_required=true` must have submissions with `status='approved'`
  - **Optional Templates**: Templates with `is_required=false` do not affect completion
  - **Status Tracking**: Only 'approved' submissions count (pending/submitted/rejected do not count)
  - **Activity Logging**: Logs 'onboarding_completed' event when completion is achieved
  - **Controller Integration**:
    - `OnboardingController::submitForm()`: Checks completion after form submission (not for drafts)
    - `OnboardingController::approveSubmission()`: Checks completion after HR approval
    - `OnboardingController::getCompletionStatus()`: GET `/v1/onboarding/completion-status` endpoint
  - **API Endpoint**: GET `/v1/onboarding/completion-status` (auth:sanctum) returns JSON with completion details
  - **Tests**: 29 comprehensive Pest tests (18 unit + 11 feature) covering all edge cases

- **Standard Onboarding Form Templates Seeder** (Issue #497, Epic #469)
  - **IMPLEMENTED** 4 pre-configured onboarding form templates (system-wide)
  - **Templates Created**:
    1. **Personal Information Form** (Required): BewachV § 16 required fields (gender, nationalities, intended activities)
    2. **Bank Account Details** (Optional): IBAN, BIC, bank name, account holder
    3. **Emergency Contact** (Optional): 2 contact persons with phone and relationship
    4. **Tax Identification Number** (Optional): Tax ID (11 digits), tax class (1-6), children count
  - **JSON Schema-Based**: Each template defines validation rules, field types, and requirements
  - **System Templates Protection**: Marked as `is_system_template = true` to prevent accidental deletion/modification
    - `OnboardingFormTemplatePolicy`: Prevents HR from deleting/editing system templates
    - Model accessors: `can_be_deleted` and `can_be_edited` flags
    - API Response: Includes protection flags for frontend UI logic
    - **Rationale**: Deleting "Personal Information Form" would prevent BewachV § 16 compliance
  - **Automatic Seeding**: Runs via `DatabaseSeeder` on fresh installations
  - **Idempotent**: Seeder can be run multiple times safely (uses `updateOrCreate`)
  - **Tests**: 20+ comprehensive Pest tests validating structure, validation rules, and data integrity

- **Magic Link Employee Onboarding System** (Issue #486, Epic #469)
  - **IMPLEMENTED** Secure single-use tokens for pre-contract employee onboarding
  - **`employee_onboarding_tokens` Table**: UUID primary key, bcrypt-hashed tokens, 7-day expiry, single-use enforcement, audit trail (IP, user agent)
  - **`EmployeeOnboardingToken` Model**:
    - `generate(Employee)`: Creates 64-char random token with 7-day expiry
    - `findByPlainToken(string)`: Constant-time comparison prevents timing attacks
    - `isValid()`: Checks expiry AND single-use status
    - `markAsCompleted(ip, userAgent)`: Enforces single-use, logs completion
  - **`OnboardingController::complete()`**: POST `/v1/onboarding/complete` endpoint
    - Public endpoint (no authentication required)
    - Token-based authentication
    - Sets employee first/last name, marks onboarding_started_at
    - Sets user password (replaces temporary password)
    - Creates Sanctum token for immediate login
    - Rate limited: 3 attempts per 10 minutes per IP
  - **Updated `OnboardingInvitationMail`**:
    - Generates `EmployeeOnboardingToken` instead of password reset token
    - Links to `/onboarding/complete?token={token}` instead of `/password/reset`
  - **Security Features**:
    - Tokens hashed with bcrypt before storage (plain text never in database)
    - Single-use enforcement via `completed_at` timestamp
    - 7-day expiry (configurable)
    - Constant-time token comparison prevents timing attacks
    - Audit trail: IP address and user agent logged on completion
    - Rate limiting: 3 attempts per 10 minutes per IP
  - **Tests**: 16 unit tests (all passing), 7 feature tests (E2E flow validated, 2 pending User accounts)

- **BewachV § 16 Employee Data Fields for BWR Registration** (Issue #468, Epic #469)
  - **IMPLEMENTED** Complete BewachV compliance for Bewacherregister (BWR) employee data management
  - **30+ New Fields** across 7 categories:
    - **BWR Tracking**: `bwr_id` (7-digit unique ID), `bwr_status` (5-state enum), `bwr_registered_at`, `bwr_submission_date`, `bwr_notes`
    - **Retention Management**: `employment_end_date`, `retention_period_end` (auto-calculated: end-of-year + 3 years)
    - **Identity Data**: `gender` (mandatory for BWR), `birth_name_enc`, `previous_names` (JSON), `birth_city`, `birth_country` (ISO 3166-1), `birth_state`
    - **Nationalities**: `nationalities` (JSON array with dual citizenship support, ISO 3166-1 alpha-2)
    - **Structured Address**: 7 encrypted fields (`address_street_enc`, `address_house_number_enc`, `address_postal_code_enc`, `address_city_enc`, `address_supplement_enc`, `address_country`, `address_state`)
    - **Address History**: `address_history` (JSON, 5-year requirement per BewachV § 16 Abs. 2 Nr. 6)
    - **Intended Activities**: `intended_activities` (JSON, §34a work types)
    - **ID Document**: `id_document_type` (enum), `id_document_number_enc`, `id_document_expiry`, `id_document_copy_path`, `id_document_copy_deleted_at`
    - **Sachkunde**: `sachkunde_ihk_number`, `sachkunde_exam_date`, `sachkunde_issued_date` (NO expiry - valid for life)
  - **Auto-Deletion System (GDPR Art. 5(1)(e))**:
    - ID document copies automatically deleted when `bwr_status` = 'active'
    - Physical file removal from storage + database timestamp
    - Activity log with legal basis: "ID document copy automatically deleted (BWR active)"
  - **Retention Period Calculation (BewachV § 21 Abs. 4)**:
    - Formula: `retention_period_end = END_OF_YEAR(termination_date) + 3 years`
    - Example: Terminated 2024-06-15 → Retention until 2027-12-31
    - Auto-calculated via Observer on status change to 'terminated'
    - Activity log with legal basis: "Retention period calculated (BewachV §21 - 3 years from end of calendar year)"
  - **Form Request Validation**:
    - BWR-ID: Exactly 7 digits (`size:7`, `regex:/^[0-9]{7}$/`), unique, preserves leading zeros
    - Gender: Required when `bwr_status` is 'pending' or 'active'
    - Address fields: Required when `bwr_status` is 'pending' or 'active'
    - ISO codes: All country/nationality fields use ISO 3166-1 alpha-2 (`size:2`, `regex:/^[A-Z]{2}$/`)
    - German validation messages: "Die Bewacher-ID muss exakt 7 Ziffern haben"
  - **EmployeeResource API**:
    - All 30+ new fields exposed in JSON response
    - Computed property `structured_address`: "Straße 42, 10115 Berlin, DE"
    - Encrypted fields decrypted automatically (no \_enc suffix in API)
  - **EmployeeFactory States**:
    - `withBwrRegistration()`: Minimal BWR data (7-digit ID, status, gender, address)
    - `withCompleteBewachvData()`: Complete § 16 dataset (BWR, identity, dual citizenship, address history, intended activities, ID document, Sachkunde)
    - `withDualCitizenship()`: Random EU country combinations
    - `withAddressHistory()`: 0-2 historical addresses (5-year period)
  - **Database**:
    - Migration: `2026_01_04_183934_add_bewachv_fields_to_employees_table.php` (296 lines)
    - 3 indexes: `bwr_status`, `retention_period_end`, `bwr_registered_at`
    - Enum constraint: `bwr_status` CHECK (5 values: not_registered, pending, active, suspended, revoked)
  - **Testing**:
    - 41 comprehensive tests (100% passing, 201 assertions)
    - Model tests (10): Encryption, JSON casting, string storage, computed properties
    - Observer tests (9): Auto-deletion, retention calculation, activity logging
    - Factory tests (8): State validation, BWR-ID generation, ISO compliance
    - Resource tests (6): API transformation, date formatting, field inclusion
    - Feature tests (8): BWR-ID format, uniqueness, conditional validation, German messages
  - **Documentation**:
    - `docs/BEWACHV_COMPLIANCE.md` (500+ lines): Complete legal reference, field mapping, workflows, GDPR compliance
  - **Quality Gates**: ✅ PHPStan Level Max, ✅ Pint compliant, ✅ 41/41 tests passing, ✅ REUSE 3.3
  - **Impact**: Foundation for Issues #471 (BWR Workflow Export), #470 (Automated Data Deletion), #472 (Work Permit Tracking)

- **Activity Log REST API with Scoped Filtering** (Issue #394, Epic #385)
  - **IMPLEMENTED** ActivityLogController with 3 RESTful endpoints for activity log access
  - Endpoints:
    - `GET /v1/activity-logs` - Paginated listing with comprehensive filtering
    - `GET /v1/activity-logs/{activity}` - Single activity with relationships
    - `GET /v1/activity-logs/{activity}/verify` - Verification results (chain + Merkle + OTS)
  - Authorization (defense-in-depth):
    1. Tenant isolation (mandatory first check)
    2. Permission check (`activity_log.read` via ActivityPolicy)
    3. Organizational scope filtering (ADR-010)
    4. Leadership level filtering (ADR-009 - only subordinates' activities)
  - Access Control Logic:
    - Users with NO scopes: See all activities (global access)
    - Users WITH scopes: See only scoped activities (NOT global activities)
    - Leadership filtering: min/max_viewable_rank controls subordinate visibility
    - System activities (no causer): Always visible within scoped units
  - Filter Parameters (11 total):
    - Date range: `from_date`, `to_date`
    - Log categorization: `log_name`
    - Text search: `search` (in description)
    - Organizational: `organizational_unit_id`
    - Polymorphic filters: `causer_type`, `causer_id`, `subject_type`, `subject_id`
    - Pagination: `per_page` (default 50, max 100)
    - Verification: `include_verification` (optional hash chain + Merkle + OTS results)
  - Components:
    - `ActivityLogController.php` (306 lines) - 3 endpoints with complex authorization
    - `ActivityResource.php` (121 lines) - JSON transformation with optional verification
    - `IndexActivityLogRequest.php` (84 lines) - Validation for 11 filter parameters
    - Routes registered in `routes/api.php` with `tenant.inject` middleware
  - Testing:
    - 24 comprehensive feature tests (100% passing, 76 assertions)
    - Test coverage: Authentication, authorization, scoped filtering, leadership levels, pagination, tenant isolation, verification
  - Quality Gates: ✅ PHPStan Level 9, ✅ Pint compliant, ✅ 24/24 tests passing
  - **Impact:** Completes Epic #385 Phase 6, unblocks Issue #395 (Frontend Activity Log Viewer), enables BewachV § 21 Abs. 4 compliance

- **ActivityPolicy with Leadership Level Filtering** (Issue #396, Epic #385)
  - **IMPLEMENTED** authorization policy for hierarchical activity log access
  - Architecture:
    - `view()` method enforces tenant isolation + organizational scope + leadership filtering
    - `viewAny()` method requires `activity_log.read` permission
    - Leadership filtering applies to activity CAUSER's rank (not subject)
  - Authorization Logic:
    1. Tenant isolation (always first - defense in depth)
    2. Permission check (`activity_log.read` required)
    3. Global activities (no org unit): Allowed if permission granted
    4. Organizational scope check: Must have scope for activity's org unit
    5. Leadership filtering: Can only view logs from subordinates (or system)
  - Rank Filter Semantics (ADR-009):
    - Guards (no leadership): Require `min_viewable_rank=0` in scope
    - Leadership ranks 1-255: Filtered by scope's min/max range
    - System activities (no causer): Always visible (no rank filtering)
    - **VALIDATED SEPARATION**: Guards (min=0) and Leadership (max>0) MUST use separate scopes
  - **Validation Rules** (prevents scope misconfiguration):
    - Backend: Custom validation in `StoreOrganizationalScopeRequest` and `UpdateOrganizationalScopeRequest`
    - Frontend: `validateRankRange()` in `leadershipLevelUtils.ts`
    - Error: "Guards (min=0) and Leadership (max>0) must use separate scopes"
    - Allowed: `min=0, max=0` (Guards only) or `min=X, max=Y` (Leadership only)
  - Testing:
    - 19 ActivityPolicy tests (100% coverage)
    - 6 validation tests (create + update for viewing + assignment ranks)
    - All scenarios: Guards, Leadership, multi-scope, system activities, NULL filters
  - Quality Gates: ✅ PHPStan Level 9, ✅ Pint compliant, ✅ All 21 tests passing
  - **Impact:** Completes Epic #385 Phase 4 (ActivityPolicy), enables BewachV § 21 Abs. 4 compliance

- **Leadership Levels Database Infrastructure** (Issue #423, Epic #399)
  - **IMPLEMENTED** database migrations for tenant-configurable leadership hierarchies per ADR-009
  - Architecture:
    - `leadership_levels` table: Tenant-specific level definitions (rank, name, description, color)
    - `employees.leadership_level_id`: Foreign key linking employees to their leadership level
    - `user_internal_organizational_scopes` rank filters: min/max viewable rank for hierarchical access control
  - Database Schema:
    - Rank system: 1 = highest authority (CEO), ascending numbers = lower organizational levels
    - Tenant isolation: Unique constraints on (tenant_id, rank) and (tenant_id, name)
    - Soft deletes supported for leadership_levels
    - ON DELETE SET NULL for employee assignments (preserves history when level deleted)
  - Seeder & Factory:
    - Default 6-level hierarchy (C-Level, Senior Management, Middle Management, Team Leads, Senior Staff, Staff)
    - Seeder is idempotent - safe to run multiple times
    - Factory with fluent methods: rank(), named(), colored(), inactive()
  - Testing:
    - 16 comprehensive migration tests (100% schema validation)
    - Factory and Seeder tests ready (awaiting LeadershipLevel model from Issue #424)
  - Quality Gates: ✅ Pint compliant, ✅ PHPStan Level 9, ✅ REUSE 3.3 compliant
  - **Next Steps:** Issue #424 (LeadershipLevel Model), #425 (API Endpoints), #426 (Frontend)
  - **Impact:** Epic #399 (Leadership-Based Access Control) - foundational infrastructure for hierarchical employee visibility

### Deprecated

- **Password Reset for Employee Onboarding** (Issue #486)
  - **DEPRECATED**: Using `Password::createToken()` for onboarding invitations
  - **Reason**: Security anti-pattern - password reset tokens are designed for password recovery, not account setup
  - **Migration Path**: Use new `EmployeeOnboardingToken` system instead
  - **Removal Timeline**: Legacy approach removed immediately (replaced in this release)
  - **Impact**: Onboarding emails now use dedicated single-use tokens with 7-day expiry

### Changed

- **BWR-ID Format and Validation** (Issue #468)
  - **BREAKING CHANGE**: BWR-ID format now strictly enforces 7-digit numeric range: `0000000` - `9999999`
  - Changed from `string(50)` to `string(7)` with regex validation: `/^[0-9]{7}$/`
  - String storage preserves leading zeros (e.g., "0012345") for legal compliance
  - Database constraint: Unique index on `bwr_id` column
  - Rationale: BewachV § 16 Bewacherregister uses 7-digit IDs exclusively

- **Sachkunde Qualification Tracking** (Issue #468)
  - Sachkunde (§ 34a) qualification is **valid for life** per IHK confirmation
  - Removed expiry date field (no longer needed)
  - Tracking: IHK number, exam date, issued date only
  - Changed from 4 fields to 3 fields (removed `sachkunde_expiry`)

### Breaking Changes

⚠️ **BewachV Employee Data Changes** (Issue #468) - Version 0.x.x allows breaking changes to avoid technical debt

- **Address Structure Change**:
  - Old: Single `address_encrypted` field with free-text format
  - New: 7 structured fields (`address_street_enc`, `address_house_number_enc`, `address_postal_code_enc`, `address_city_enc`, `address_supplement_enc`, `address_country`, `address_state`)
  - Impact: API responses now include 7 separate address fields instead of 1
  - Migration Path: Use computed property `structured_address` for comma-separated format in UI
  - Code Changes Required: Update API clients to handle new address structure

- **BWR-ID Validation Change**:
  - Old: `string(50)` with no format validation
  - New: Exactly 7 digits (`size:7`, `regex:/^[0-9]{7}$/`), unique constraint
  - Impact: Existing BWR-IDs with ≠7 digits will fail validation
  - Migration Path: Update existing records to 7-digit format before deployment (data cleanup script recommended)
  - Code Changes Required: Update any hardcoded BWR-ID test data to 7-digit format

- **Sachkunde Expiry Removal**:
  - Old: `sachkunde_expiry` date field for tracking qualification expiry
  - New: No expiry field (Sachkunde valid for life)
  - Impact: `sachkunde_expiry` no longer exists in model/database/API
  - Migration Path: Remove any UI/logic that relies on Sachkunde expiry dates
  - Code Changes Required: Update frontend to remove Sachkunde expiry displays/warnings

- **Factory Address Changes**:
  - Old: Generated `address_encrypted` via Factory default state
  - New: Requires 7 structured address fields
  - Impact: Old factory calls without address fields will fail validation for BWR employees
  - Migration Path: Use `withBwrRegistration()` or `withCompleteBewachvData()` states for BWR employees
  - Code Changes Required: Update all test factories to use new states or provide structured address fields

### Security

- **Enhanced Encryption for Personal Data** (Issue #468)
  - All new BewachV § 16 identity fields use `EncryptedWithDek` cast (encryption at rest)
  - Encrypted fields: `birth_name_enc`, `address_street_enc`, `address_house_number_enc`, `address_postal_code_enc`, `address_city_enc`, `address_supplement_enc`, `id_document_number_enc`
  - Blind indexes maintained for unique constraints (BWR-ID, email, etc.)
  - Security Enhancement: 7 fields now encrypted vs. 1 previously (address)
  - Compliance: GDPR Art. 32 (Security of Processing)

- **Queue-based Activity Hash Chain Building** (Issue #408, PR #419)
  - **IMPLEMENTED** race-condition-free hash chain processing via Laravel queues
  - Eliminates race condition window from synchronous `creating` hook approach
  - Architecture:
    - ProcessActivityHashChain job dispatched in Activity::created hook
    - DB transaction + lockForUpdate() ensures sequential processing per tenant
    - Uses DB::table()->update() to bypass Eloquent events (no infinite loops)
    - Environment-based dispatch: dispatchSync() in tests, dispatch() in production
  - Performance validation:
    - 134 logs/sec sustained throughput (exceeds 100 logs/sec target)
    - Zero broken links across 200-log stress test (100% chain integrity)
    - p95 latency: 11.82ms per log (excellent responsiveness)
    - Multi-tenant concurrent processing maintains perfect isolation
  - Testing:
    - 5 new performance tests (370 assertions)
    - All 1611/1612 existing tests pass (99.92% success rate)
    - ProcessActivityHashChainTest.php: 5/5 tests validate job behavior
  - **BREAKING CHANGE:** event_hash column now nullable (Migration 2025_12_24_162643)
    - Reason: Activity INSERT happens before job computes hash
    - Timeline: INSERT (event_hash=NULL) → Job runs → UPDATE (event_hash=computed)
    - Duration: Milliseconds (sync queue) to seconds (async queue worker)
    - **ACTION REQUIRED:** Run migration: `php artisan migrate`
  - **PRODUCTION REQUIREMENT:** Queue worker must be running
    - Command: `php artisan queue:work --queue=activity-hash-chain,merkle,opentimestamp,default`
    - Setup: Configure supervisor/systemd for daemon process
    - See: docs/QUEUE_WORKERS.md for detailed setup instructions
  - Test adaptation: Tests using Activity::create() must call $log->refresh() to reload event_hash
  - **Impact:** Epic #385 (BewachV compliance) - eliminates last race condition in forensic audit trail

### Security

- **OpenTimestamp Proof Verification - Secure Implementation** (Issue #415, PR #417)
  - **IMPLEMENTED** secure CLI-based verification using official `ots verify` tool
  - Replaces vulnerable "hybrid approach" that was reverted in PR #413
  - Uses vetted OpenTimestamps CLI client for cryptographically sound verification
  - Implementation details:
    - ProcessExecutor abstraction for testable CLI calls
    - Full operation tree parsing via official OTS client
    - Bitcoin blockchain attestation verification (block height + Merkle proof)
    - Cross-check with actual Bitcoin transaction data
    - Digest normalization (lowercase) for OTS CLI compatibility
  - Security advantages:
    - ✅ No custom crypto code (delegates to OTS experts)
    - ✅ Cryptographic validation of operation chain
    - ✅ Blockchain cross-verification included
    - ✅ Fail-safe behavior when ots CLI not installed
  - Testing:
    - 17 OpenTimestamp verification tests added (41 assertions)
    - Entire test suite: 43 tests passing (128 assertions)
    - Comprehensive unit tests with mocked ProcessExecutor
    - Feature tests cover submit, upgrade, verify workflows
    - Activity model integration tests updated
  - Documentation:
    - Installation: `pip install opentimestamps-client`
    - CLI reference: <https://github.com/opentimestamps/opentimestamps-client>
  - **Impact:** Level 3 audit trail verification now FULLY FUNCTIONAL and secure
  - **Note:** Completes security fix from Issue #412 (revert) → Issue #415 (secure implementation)

### Legal Compliance

- **BewachV § 16 Implementation** (Issue #468)
  - **Full Compliance**: All mandatory registration fields per BewachV § 16 Bewacherregister
  - Required fields: BWR-ID (7 digits), gender, structured address, nationality, identity documents, Sachkunde (if applicable)
  - Conditional validation: Gender + address required when `bwr_status` is 'pending' or 'active'
  - 5-year address history tracked per BewachV § 16 Abs. 2 Nr. 6

- **BewachV § 21 Abs. 4 Retention** (Issue #468)
  - **Automated Retention**: Calculates 3-year retention from end of calendar year
  - Formula: `END_OF_YEAR(termination_date) + 3 years`
  - Auto-calculated via Observer on status change to 'terminated'
  - Activity log records legal basis: "Retention period calculated (BewachV §21)"
  - Future: Batch deletion queries will use `retention_period_end` for GDPR compliance

- **BewachV § 34a Sachkunde** (Issue #468)
  - **Clarification**: Sachkunde qualification valid for life (no expiry per IHK)
  - Tracking: IHK number, exam date, issued date
  - Field removed: `sachkunde_expiry` (incorrect assumption)
  - Compliance: Accurate representation of German security guard qualification rules

- **GDPR Art. 5(1)(e) Storage Limitation** (Issue #468)
  - **Auto-Deletion System**: ID document copies automatically deleted when BWR registration complete
  - Trigger: `bwr_status` change to 'active'
  - Action: Physical file deletion + `id_document_copy_deleted_at` timestamp
  - Activity log records legal basis: "ID document copy automatically deleted (BWR active)"
  - Justification: Documents no longer needed once BWR registration confirmed

- **GDPR Art. 30 Records of Processing** (Issue #468)
  - **Audit Trail**: All BewachV data changes logged via Spatie ActivityLog
  - Logged events: BWR status changes, retention calculation, ID document deletion
  - Properties: Old/new values stored as Collections for immutability
  - Retention: Activity logs retained per `retention_period_end` (BewachV § 21)
  - Compliance: Complete audit trail for regulatory inspections

### Documentation

- **BewachV Compliance Documentation** (Issue #468)
  - Created `docs/BEWACHV_COMPLIANCE.md` (500+ lines)
  - Sections:
    - Legal Framework: BewachV §16/§21/§34a full text with translations
    - BWR-ID Implementation: 7-digit format specification, string storage rationale
    - Field Mapping: Complete table of 30+ fields with data types, encryption, validation
    - Auto-Deletion Workflow: Observer implementation with code, diagram, edge cases
    - Retention Calculation: Formula explanation, examples, batch deletion queries
    - Sachkunde Clarification: Valid for life, IHK confirmation
    - Breaking Changes: Address structure, BWR-ID format, Sachkunde expiry
    - GDPR Compliance: Encryption, storage limitation, audit logging
    - Testing & Validation: 41 tests, quality gates, manual checklist
  - Audience: Legal teams, auditors, compliance officers, senior developers
  - Reference: Link in PR #[TBD] and Issue #468

- **OpenTimestamp Proof Verification - Security Fix** (Issue #412, PR #413)
  - **REVERTED** unsecure "hybrid approach" implementation after Copilot security review
  - Previous implementation was vulnerable to trivial proof forgery attacks
  - Security flaws identified:
    - extractCommitment() blindly extracted first 32 bytes (no operation tree parsing)
    - hasAttestation() only checked substring match (no cryptographic validation)
    - No Bitcoin blockchain cross-verification (block height, Merkle proof)
    - No operation chain validation (SHA256 operations not verified)
  - `verify()` method initially failed closed (returned false) until secure implementation available
  - Comprehensive documentation added explaining security concerns
  - Issue #412 kept OPEN for proper implementation (Option C: CLI wrapper)

### Added

- **Activity Logging (Forensic Audit Trail) - Merkle Proof Verification Tests** (Issue #390, Epic #385)
  - Detects tampered log event hash (modified after batching)
  - Detects manipulated sibling hashes in proof chain
  - Validates invalid proof format handling
  - Validates missing hash/position field validation
  - Added performance tests in [tests/Performance/MerkleProofPerformanceTest.php](tests/Performance/MerkleProofPerformanceTest.php)
    - 100-leaf tree verification: ~1.4ms per log including DB overhead
    - 4-leaf tree verification: ~1.2ms per log including DB overhead
    - Pure algorithm performance: <0.1ms (DB refresh() adds ~1.3ms overhead)
  - All 7 new tests passing with ≥95% coverage
  - verifyMerkleProof() method fully covered and validated

### Removed

- **Deprecated Employee Address Fields** (Issue #468)
  - **BREAKING CHANGE**: Removed `address_encrypted` single-field storage
  - Replaced by 7 structured address fields: `address_street_enc`, `address_house_number_enc`, `address_postal_code_enc`, `address_city_enc`, `address_supplement_enc`, `address_country`, `address_state`
  - Migration provides backward compatibility via data extraction
  - Computed property `structured_address` provides comma-separated format for backward compatibility

- **Sachkunde Expiry Field** (Issue #468)
  - **BREAKING CHANGE**: Removed `sachkunde_expiry` from Employee model
  - Sachkunde qualification never expires (valid for life per IHK)
  - Database column not created in new migrations
  - Factory no longer generates expiry dates for Sachkunde

- **🎉 MAJOR: Production-Ready Multi-Tenant Architecture** (Epic #357)
  - User-based tenant resolution via `users.tenant_id` foreign key relationship
  - Every user belongs to exactly ONE tenant (database-enforced with NOT NULL constraint)
  - `InjectTenantId` middleware updated to resolve tenant from authenticated user
  - Tenant-scoped RBAC using Spatie Permission's team feature (team_id = tenant_id)
  - Complete tenant isolation: users can only access data from their assigned tenant
  - 45+ comprehensive tenant isolation tests validating security boundaries
  - Zero-downtime migration path for existing deployments (3-step migration process)
  - **Documentation:**
    - [ADR-008: User-Based Tenant Resolution](https://github.com/SecPal/.github/blob/main/docs/adr/20251219-user-based-tenant-resolution.md)
    - [Multi-Tenant Deployment Guide](/docs/guides/multi-tenant-deployment.md)
    - [Tenant Provisioning Guide](/docs/guides/tenant-provisioning.md)
    - [Migration Guide: Single → Multi-Tenant](/docs/migration-guides/single-to-multi-tenant.md)
    - [RBAC Architecture - Multi-Tenant Context](/docs/rbac-architecture.md#0-multi-tenant-context-foundation)
  - **Related Issues:** #358 (User → Tenant relationship), #359 (InjectTenantId update), #360 (Registration), #361 (Tenant isolation tests)

### Changed

- **BREAKING: User → Tenant Relationship** (Issue #358, Epic #357)
  - Added `users.tenant_id` foreign key to `tenant_keys` table (NOT NULL)
  - Migration includes 3-step process: add nullable column → backfill data → make NOT NULL
  - All existing users automatically assigned to first tenant (backward-compatible for single-tenant deployments)
  - User model now includes `tenant()` relationship: `$user->tenant`
  - TenantKey model now includes `users()` relationship: `$tenant->users`
  - Cascade delete: Deleting tenant deletes all its users
  - **Migration Files:**
    - `2025_12_18_193721_add_tenant_id_to_users_table.php`
    - `2025_12_18_193745_backfill_user_tenant_ids.php`
    - `2025_12_18_193808_make_user_tenant_id_not_nullable.php`

- **BREAKING: InjectTenantId Middleware - User-Based Resolution** (Issue #359, Epic #357)
  - Replaced hardcoded `TenantKey::oldest('id')` with user-based resolution: `$user->tenant_id`
  - Middleware now requires authenticated user (returns 401 for unauthenticated requests to tenant-scoped routes)
  - Security hardening: Client-provided `tenant_id` parameters (query string or request body) are **always removed**
  - Sets Spatie Permission team ID: `app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id)`
  - Updated documentation comments from "SINGLE-TENANT DEVELOPMENT MODE" to "PRODUCTION MULTI-TENANT MODE"
  - **Impact:** All API routes using `tenant.inject` middleware now require authentication and resolve tenant from user

- **BREAKING: Registration/User Creation - Tenant Assignment** (Issue #360, Epic #357)
  - User registration now requires or auto-assigns `tenant_id`
  - If no `tenant_id` provided, defaults to first available tenant (MVP behavior)
  - Admin user creation validates that tenant_id matches admin's tenant (cannot create users in other tenants)
  - UserFactory updated to include `tenant_id` attribute
  - DatabaseSeeder updated to explicitly assign users to tenants
  - **Impact:** Cannot create users without valid tenant_id (FK constraint violation)

- **RBAC Architecture - Tenant-Scoped Permissions** (Epic #357)
  - All role assignments now tenant-scoped via Spatie Permission's team feature
  - User with role "Admin" in Tenant 1 ≠ User with role "Admin" in Tenant 2 (separate assignments)
  - Permission checks automatically scoped to user's tenant
  - Cross-tenant policy checks return 404 (resource not found in user's tenant)
  - Updated [RBAC Architecture documentation](/docs/rbac-architecture.md) with Multi-Tenant Context section

### Security

- **CRITICAL: Fixed tenant_id spoofing vulnerability in InjectTenantId middleware** (PR #356)
  - Client-provided `tenant_id` parameters (query string or request body) are now **always rejected**
  - Prevents cross-tenant data access attacks in multi-tenant deployments
  - Affected: All controllers using `tenant.inject` middleware
  - Root cause: Middleware accepted client-side tenant_id without validation
  - Fix: Middleware now explicitly removes client parameters before injecting server-resolved tenant_id
  - Impact: Security hardening for current single-tenant development mode, **critical** for future multi-tenant production (Epic #357)
  - Related: Issue #190 (tenant resolution), Epic #357 (Production-Ready Multi-Tenant Architecture)

### Added

- **Site CRUD API endpoints** (#314, Phase 4.2 of Epic #210, PR #356)
  - Implemented 6 RESTful endpoints for Site management:
    - `GET /v1/sites` - Paginated list with comprehensive filtering
      - Filters: customer_id, organizational_unit_id, type (permanent/temporary), is_active, currently_valid, search
      - Need-to-Know access: Users see sites via org unit access OR direct assignment OR customer assignment
      - Search: name, site_number, customer name (case-insensitive)
      - Pagination: Default 15 per page (configurable)
    - `POST /v1/sites` - Create site with auto-generated site_number (OBJ-YYYY-####)
      - Validates organizational unit access for user
      - Required: name, customer_id, organizational_unit_id, type, address
      - Optional: contact, access_instructions, notes, metadata, validity dates
      - Supports GPS coordinates (latitude/longitude) in address
    - `GET /v1/sites/{site}` - Show site with relationships (customer, organizationalUnit, assignments, costCenters)
    - `PATCH /v1/sites/{site}` - Update site (PATCH semantics, all fields optional)
      - Validates organizational unit access if changed
      - Conditional field visibility: access_instructions and notes only for users with update permission
    - `DELETE /v1/sites/{site}` - Soft delete site
      - Will block deletion if active cost centers exist
    - For cost center management under sites, see CostCenter API endpoints below
  - Authorization: Need-to-Know enforcement with SitePolicy
    - View access: Site assignment OR organizational unit access OR customer assignment
    - Create: Requires sites.create permission + access to organizational unit
    - Update: Requires sites.update permission + view access
    - Delete: Requires sites.delete permission + view access
  - SiteResource with conditional field visibility (access_instructions, notes hidden for read-only users)
  - 41 comprehensive feature tests covering all endpoints
  - Full integration with Customer & Assignment APIs

- **Assignment API endpoints** (#315, Phase 4.3 of Epic #210, PR #363)
  - Customer Assignments: Flexible user-to-customer role assignments with tenant-specific terminology
    - `GET /v1/customers/{customer}/assignments` - List assignments for customer (filters: role, active_only)
    - `POST /v1/customers/{customer}/assignments` - Create assignment (prevents duplicates with 409)
    - `PATCH /v1/customer-assignments/{assignment}` - Update assignment (PATCH semantics)
    - `DELETE /v1/customer-assignments/{assignment}` - Delete assignment
  - Site Assignments: Flexible user-to-site role assignments
    - `GET /v1/sites/{site}/assignments` - List assignments for site (filters: role, active_only)
    - `POST /v1/sites/{site}/assignments` - Create assignment (prevents duplicates with 409)
    - `PATCH /v1/site-assignments/{assignment}` - Update assignment
    - `DELETE /v1/site-assignments/{assignment}` - Delete assignment
  - User Assignments: Retrieve authenticated user's assignments
    - `GET /v1/me/customer-assignments` - Get my customer assignments (filter: active_only)
    - `GET /v1/me/site-assignments` - Get my site assignments (filter: active_only)
  - Authorization: `customers.read` for viewing, `assignments.create/update/delete` for mutations
  - Created controllers, form requests, API resources for all endpoints
  - Full SiteResource implementation replacing placeholder

- **CostCenter API endpoints** (#316, Phase 4.4 of Epic #210, PR #368)
  - Implemented 5 RESTful endpoints for CostCenter management (nested under sites):
    - `GET /v1/sites/{site}/cost-centers` - List cost centers for site (filter: active_only)
    - `POST /v1/sites/{site}/cost-centers` - Create cost center
    - `GET /v1/sites/{site}/cost-centers/{costCenter}` - Show cost center details
    - `PUT /v1/sites/{site}/cost-centers/{costCenter}` - Update cost center
    - `DELETE /v1/sites/{site}/cost-centers/{costCenter}` - Soft delete cost center
  - Validation: code (unique per site, max 50 chars), name (required, max 255 chars)
  - Authorization via CostCenterPolicy: Inherits access from parent site
  - 24 comprehensive feature tests covering all CRUD operations
  - CostCenterResource for consistent API responses
  - Full integration with Site API

- **Customer & Site Management Database Schema** (#308, Phase 1 of Epic #210)
  - Created `customers` table for client organizations with flat structure (no hierarchies):
    - UUID primary key with tenant isolation
    - Auto-generated customer_number (KD-YYYY-#### format, unique per tenant)
    - Company name and JSONB billing_address (street, city, postal_code, country)
    - JSONB contact information (name, email, phone, position)
    - is_active flag, notes, extensible metadata JSONB field
    - Soft deletes support
    - Indexes: unique (tenant_id, customer_number), (tenant_id, is_active), (tenant_id, name)
  - Created `sites` table for physical locations where security services are provided:
    - UUID primary key with tenant isolation
    - Foreign keys to customers and organizational_units
    - Auto-generated site_number (OBJ-YYYY-#### format, unique per tenant)
    - Site name and type enum ('permanent', 'temporary')
    - JSONB address with GPS coordinates (street, city, postal_code, country, lat, lng)
    - JSONB contact information (name, email, phone, position)
    - access_instructions for guard briefing, notes, extensible metadata
    - is_active flag with validity period (valid_from, valid_until) for temporary sites
    - Soft deletes support
    - Indexes: unique (tenant_id, site_number), (tenant_id, customer_id), (tenant_id, organizational_unit_id), (tenant_id, type, is_active)

### BREAKING CHANGES

- **Replaced Customer/Object Schema with Epic #210 Design** (#308)
  - **Removed deprecated Guard Book schema (November 2025)**:
    - Deleted tables: `customers` (hierarchical), `customer_closures`, `objects`, `object_areas`, `customer_user_accesses`, `customer_user_object_accesses`, `guard_books`, `guard_book_reports`
    - Deleted models: `Customer`, `CustomerClosure`, `SecPalObject`, `ObjectArea`, `CustomerUserAccess`, `CustomerUserObjectAccess`, `GuardBook`, `GuardBookReport`
    - Deleted factories: `CustomerFactory`, `SecPalObjectFactory`, `ObjectAreaFactory`, `CustomerUserAccessFactory`, `CustomerUserObjectAccessFactory`
  - **Rationale**: Pre-v1.0.0 allows clean architectural changes. Old schema was hierarchical and Guard Book-specific; Epic #210 requires flat customer structure for broader security services (patrol, alarm response, event security)
  - **Migration strategy**: Complete schema replacement (no ALTER TABLE migrations). Use `php artisan migrate:fresh` for clean database rebuild
  - **Impact**: Any code referencing old Customer/Object models must be updated to use new Customer/Site models (Epic #210 Phases 2-7)

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

- **Employee Creation Encryption Issue** (#323, resolves #339)
  - Fixed NULL constraint violation on encrypted fields (`first_name_enc`, `last_name_enc`) during employee creation
  - Root cause: `$fillable` array only contained `_enc` field names, preventing plaintext mutators from triggering
  - Solution: Added plaintext field names (`first_name`, `last_name`, `date_of_birth`, `address`, `hourly_rate`, `tax_id`, `social_security_number`) to `$fillable` array in Employee model
  - Ensured `tenant_id` is set FIRST in data array to enable EncryptedWithDek cast to access tenant DEK
  - All 30 EmployeeControllerTest tests now pass (previously 9/30 failing on creation)

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
