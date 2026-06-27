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

- added a public unauthenticated `GET /v1/source` AGPL source-offer endpoint that keeps the canonical license, repository, copyright, and warranty payload while also returning explicit network-use source-offer notices for SecPal users
- added the public `GET /v1/bootstrap` runtime-discovery endpoint for Android pre-login instance validation, deriving the canonical `api_base_url` from `APP_URL`, failing closed with documented `500`/`503`/`426` bootstrap responses when deployment metadata is incomplete or incompatible, and covering the success/failure slices with focused Pest tests plus operator bootstrap configuration docs (refs `api#1115`)
- added authenticated Android notification-installation registration on the customer-hosted backend via the canonical `PUT/DELETE /v1/me/notification-installations/{installationId}` surface, including tenant-safe encrypted FCM token storage, bootstrap-advertised public Android runtime metadata on `GET /v1/bootstrap`, deterministic `409` fail-closed handling for disabled or stale runtime state, and focused Pest coverage plus deployment/operator documentation for the `BOOTSTRAP_ANDROID_PUSH_*` settings (refs `api#1123`)
- added authenticated browser Web Push notification-installation registration, rotation, and revocation on the customer-hosted backend via the same canonical `PUT/DELETE /v1/me/notification-installations/{installationId}` surface, including browser session+CSRF support, public VAPID bootstrap metadata on `GET /v1/bootstrap?client_platform=browser`, tenant-safe encrypted subscription endpoint/key storage with origin-only support metadata, deterministic `409` fail-closed handling for unsupported or stale Web Push runtime state, and focused Pest coverage plus deployment/operator documentation for the `BOOTSTRAP_WEB_PUSH_*` settings (refs `api#1133`)
- added customer-owned Android push delivery primitives for the customer-hosted backend, including backend-only `ANDROID_PUSH_FCM_*` operator configuration, direct FCM HTTP v1 send and queue job support, safe stale-token cleanup on invalid delivery outcomes, bootstrap non-leak coverage for private provider credentials, and deployment documentation that removes any dependency on SecPal-operated push routing for customer-owned installations (refs `api#1122`)
- added customer-owned browser Web Push delivery primitives for the customer-hosted backend, including backend-only `WEB_PUSH_VAPID_*` and `WEB_PUSH_DELIVERY_*` operator configuration, audited `minishlink/web-push` delivery, tenant-scoped deduplicated queue fan-out for targeted browser subscriptions, safe stale-subscription cleanup for expired or corrupted subscription material, and focused non-leak coverage proving private VAPID credentials plus full subscription endpoints/keys stay server-side (refs `api#1134`)
- added an operator runbook for auditing public Android Firebase bootstrap key restrictions, unrelated Google API exposure, quota and billing-alert posture, and the current App Check non-applicability for SecPal push bootstrap deployments (refs `api#1174`)
- hardened customer-owned Android push delivery configuration to fail closed when `ANDROID_PUSH_FCM_TOKEN_URI` or `ANDROID_PUSH_FCM_API_BASE_URL` are overridden to non-Google hosts, added focused configuration/service/job regression coverage for the invalid-endpoint cases, and updated deployment guidance so operators treat those settings as canonical Google endpoints instead of generic proxy hooks (refs `api#1174`)

### Changed

- `GET /v1/bootstrap?client_platform=browser` now keeps the AGPL `legal.source_url` disclosure while limiting public notification runtime metadata to the browser-relevant `web_push` channel, so browser clients no longer receive Android FCM bootstrap metadata
- public notification runtime discovery now uses the canonical shared channel contract end-to-end: `GET /v1/bootstrap` returns exhaustive `features.notification_channels` flags plus per-channel `notification_channels.android_fcm` / `notification_channels.web_push` runtime metadata, `schema_version` is now `3`, the legacy top-level Android `android_push` bootstrap alias is removed, and authenticated installation conflicts fail closed with generic `NOTIFICATION_CHANNEL_UNSUPPORTED` / `NOTIFICATION_RUNTIME_STATE_INVALID` payloads keyed by channel (refs `api#1132`)
- `DELETE /v1/me/notification-installations/{installationId}` no longer checks whether the push channel is enabled before revoking; revocation succeeds regardless of the channel's current deployment state, so clients can always clean up stale registrations on logout or uninstall even if the operator later disables the channel
- **Breaking:** Employee residential addresses live in `employee_addresses` (encrypted street/postal/city fields per row, optional residence date range). The API uses `addresses[]` instead of flat `address_*` attributes and `address_history` JSON on `employees`. Legacy values are migrated into `employee_addresses` during rollout, but clients must adopt `addresses[]` going forward.
- **Breaking:** The `PASSKEY_AUTHENTICATION_MEDIATION` environment variable and the `passkeys.authentication_mediation` config key have been removed. The passkey authentication challenge mediation is now always `optional`; remove the env var from your deployment configuration.
- **Breaking:** `POST /v1/auth/passkeys/challenges` and `POST /v1/auth/token/passkeys/challenges` are now discoverable-only. Public passkey challenge startup no longer accepts `email`, the response no longer exposes email-scoped `allow_credentials`, and the now-unused `PASSKEY_AUTHENTICATION_FALLBACK_SECRET` / `passkeys.authentication_fallback_*` configuration has been removed.
- **Breaking:** Passkey **enrollment** at `POST /v1/me/passkeys/challenges/registration` now defaults to discoverable / resident credentials. `passkeys.resident_key` defaults to `required` (was `preferred`) and `passkeys.require_resident_key` defaults to `true` (was `false`); unrecognized `PASSKEY_RESIDENT_KEY` values are coerced back to `required`, and `require_resident_key` is forced to `true` whenever `resident_key === 'required'` so configuration drift cannot silently re-enable non-discoverable enrollment. Existing non-discoverable passkey credentials remain readable through `/v1/me/passkeys` but cannot be used for the discoverable-only public passkey login flow; affected users must sign in with primary credentials and enroll a new discoverable passkey. There is no public, email-scoped recovery path — reintroducing one would re-open the enumeration vector that the discoverable-only login contract closed. See [`docs/deployment.md` § Passkey Enrollment Contract](docs/deployment.md#4-passkey-enrollment-contract) for the operator/user runbook.
- **Breaking:** Permission definitions are now strictly code-owned and seed-managed. The runtime API no longer exposes a permission-catalog management surface; `/v1/permissions*` has been removed, and the obsolete runtime permissions `permissions.create`, `permissions.update`, and `permissions.delete` are no longer seeded.

### Security

- single-shot identity-proof enforcement on `POST /v1/onboarding/complete`: a failed date-of-birth check or a name that deviates too far from the HR record now permanently burns the magic link (`invalidated_at`, `invalidated_from_ip`, `invalidated_user_agent`, `invalidation_reason` recorded on `employee_onboarding_tokens`). A single generic 422 is returned for all identity mismatches so an attacker cannot use the response as a per-field oracle. HR must issue a fresh invitation after any failed identity proof.
- enforced email verification on the HR onboarding-review endpoints (`POST /v1/onboarding-review/submissions/{submission}/approve`, `POST /v1/onboarding-review/submissions/{submission}/reject`, `POST /v1/onboarding-review/employees/{employee}/confirm`) by moving them into a dedicated route group with `verified` middleware, so unverified HR accounts can no longer approve, reject, or confirm onboarding dossiers

### Fixed

- stabilized the serial customer/site/employee number concurrency regressions and the web-push delivery fixture clock handling so full preflight and the complete Pest suite no longer deadlock or fail from stale hard-coded delivery expiry timestamps
- moved the bootstrap environment file-name override test probe out of `tests/Unit/TestCaseBootstrapEnvironmentFileTest.php` file scope into a PSR-4 compliant `Tests\Support\...` helper so `composer install` / optimized autoload generation no longer emits a `Class TestCaseBootstrapEnvironmentFileNameOverrideProbe ... does not comply with psr-4 autoloading standard` warning during development and CI bootstrap
- isolated local API test bootstrap from deployment-oriented `.env` drift by generating a dedicated test-only env file for `php artisan test`, ignoring repository `BOOTSTRAP_*` deployment flags during suite bootstrap-default assertions, and restricting intentional local env passthrough to PostgreSQL connection keys (`DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`) while providing a deterministic test `APP_KEY` plus updated local test-environment documentation (refs `api#1148`)
- adopted a shared `App\Support\ApiTimestamp` serializer across API resources, controllers, and service-built response payloads so canonical response timestamps now consistently normalize to UTC whole-second strings with a trailing `Z`; notification-installation timestamps now use the same contract-approved serializer and focused Pest coverage locks the format in place (refs `api#1138`)
- fixed public bootstrap `api_base_url` derivation so `APP_URL` values with unrelated path prefixes now fail closed instead of publishing an invented nested API path, while `APP_URL` values already ending in `/v1` continue to resolve to a single canonical `/v1` base URL
- wrapped the optional `pg_trgm` `CREATE EXTENSION` and `gin_trgm_ops` `CREATE INDEX` steps of the `create_address_data_tables` migration in nested `DB::transaction()` calls so a PostgreSQL statement error (e.g. when `pg_trgm` lives in a schema outside the connection's `search_path`, as can happen in Polyscope preview schemas) only rolls back its own SAVEPOINT instead of aborting the migration's outer DDL transaction. Previously the silently-caught error left the transaction in `aborted` state, causing the subsequent `COMMIT` to behave like `ROLLBACK` and silently drop the just-created `address_data_imports` / `address_streets` tables while the migrator still recorded the migration as ran, which surfaced downstream as `AddressDataSeeder` printing `Skipped: address data tables are missing; run migrations before setup import.` after `php artisan migrate:fresh --seed`
- scoped `GET /v1/roles`, `GET /v1/roles/{id}`, `PATCH /v1/roles/{id}`, and `DELETE /v1/roles/{id}` to the authenticated tenant so cross-tenant role enumeration and mutation by raw role id now return `404` (refs `api#1078`)
- hardened the password reset request endpoint against user-enumeration timing leaks by always paying the reset-token bcrypt hashing cost before branching on account existence, while preserving identical client responses and avoiding token or mail side effects for unknown emails
- made tenant KEK setup safe under parallel Pest workers by introducing `TenantKey::ensureKekExists()` and switching `TenantKey::generateKek()` to an atomic temp-write + `link()` publish strategy: the KEK is fully written, `chmod`-ed to `0600`, and only then hard-linked into the canonical path, so concurrent readers either observe a complete `SODIUM_CRYPTO_SECRETBOX_KEYBYTES` KEK or no file at all — never a partial or zero-length one. Race-lost `link()` failures (EEXIST + canonical path already published) are silently treated as success; all other failure modes (permission denied, missing parent, read-only filesystem) surface as `RuntimeException` with the underlying PHP error attached. Test factories (`TenantKeyFactory`, `EmployeeFactory`, `CustomerFactory`, etc.), seeders, and test setup blocks now call `ensureKekExists()` instead of the racy `if (! file_exists) generateKek()` pattern that previously caused `php artisan test --parallel` to fail with `fopen(...): Failed to open stream: File exists`. A `pcntl_fork`-based regression test races 8 concurrent workers on the same KEK path and verifies all of them succeed (refs `api#1106`)
- aligned passkey enrollment with the discoverable-only public login contract by defaulting `passkeys.resident_key` to `required` and `passkeys.require_resident_key` to `true`, coercing unrecognized `PASSKEY_RESIDENT_KEY` values back to `required`, forcing `require_resident_key` to `true` whenever `resident_key === 'required'`, and enforcing `require_resident_key=false` whenever `resident_key === 'discouraged'` so a misconfigured config pair cannot produce a WebAuthn spec-violating credential options object (the `discouraged`+`require_resident_key=true` pairing is forbidden by WebAuthn Level 2 §5.4.6); covered by unit and feature tests for `POST /v1/me/passkeys/challenges/registration` (refs `api#1104`)
- hardened the public passkey authentication challenge endpoints by removing email-scoped credential lookup and fallback `allow_credentials` generation entirely, so both browser and token challenge creation stay on the same discoverable-only contract, avoid pre-auth user/passkey lookups, and reject legacy email-scoped startup payloads instead of branching on account state
- ignored client-supplied tenant identifiers when recording failed login activity, so spoofed `tenant_id` values can no longer misattribute SPA or token login failures to another tenant
- hardened the email/password login path against user-enumeration timing leaks by always running the configured password hasher against a placeholder hash when no account matches the submitted email, so `/v1/auth/login` and `/v1/auth/token` response times no longer leak account existence through the short-circuited `Hash::check()` call in `AuthController::validatePrimaryCredentials()`
- blocked direct `POST /v1/employees` writes to `bwr_status` and `bwr_registered_at` so employee creation can no longer bypass the dedicated BWR status workflow and its audit trail
- skipped `AddressDataSeeder` gracefully when `address_data_imports` or `address_streets` is unavailable during setup seeding, so fresh workspace provisioning no longer aborts the full `db:seed` run on partial or drifted address-data schemas
- made `addresses:check` treat missing address-data tables like an unavailable dataset instead of aborting with a database exception, so partially provisioned workspaces can still report status cleanly
- normalized BWR export readiness `errors` responses to stable field codes independent of request locale, and exposed translated human messages separately via `error_messages`
- fixed address autocomplete availability checks to return `503 address_data_unavailable` when address data tables are missing, instead of leaking a database exception as a 500
- stopped exposing `employee_qualifications.document_path` through the public qualification attach/update API and `EmployeeQualificationResource`; the storage path remains internal and regression coverage now proves requests ignore it while list/show responses omit it
- renamed the `resided_from` field label in the current-address block of the residential address history onboarding form from "Living There Since" to "Date You Started Living There" for clarity
- fixed OpenPLZ address-import retention so pruning only removes superseded imports for the active country and keeps the prior successful dataset even when failed attempts exist between successful runs
- made the `birth_state` and `employee_addresses.state` cleanup migrations intentionally irreversible in `0.x`, so rollbacks no longer recreate removed compatibility columns
- fixed BWR address-history continuity check reporting false-positive gaps when an employee has a pre-window historical address followed by a current address that fully covers the 5-year export window; segments ending before the window start are now discarded before the merge pass
- aligned onboarding attachment gating with the draft-first upload flow by allowing first-time submitted forms to opt into identity/residence uploads before a submission id exists, while keeping the stricter `document_subtype` requirement for existing editable submissions and legacy `id_document` uploads during resubmission
- made onboarding PATCH merges treat `null` as an explicit key-removal sentinel for `form_data`, so stale draft keys can be cleared without a full replace while submit-time schema validation still evaluates the effective post-removal payload
- fixed PATCH onboarding submission updates to merge partial `form_data` with the stored draft before schema validation and persistence, so submit-time validation now sees the full effective payload instead of only the incoming delta; guarded against list-type root payloads that would otherwise corrupt the stored associative object with numeric keys
- aligned the default onboarding-step fallback with the invitation email by adding the missing `emergency_contact` step to pre-contract onboarding initialization paths
- aligned the onboarding invitation email copy with the actual onboarding flow by listing the required residential-address, nationality/residence, and tax steps explicitly, moving qualifications into the optional summary, and adding regression coverage for the rendered mail content
- fixed optional onboarding template validation to keep undeclared-key schema checks authoritative even when declared fields are empty, so payloads with undeclared keys now fail `additionalProperties` validation instead of bypassing it as `semantically empty`
- avoided unnecessary locale user-resolution work in the global middleware pass for bearer-token requests too, while still allowing the later API pass to honor authenticated `preferred_locale` overrides
- scoped onboarding submission `form_template_id` existence validation to the authenticated tenant plus global templates, so cross-tenant template UUID probes now fail with `422` validation errors instead of leaking into later tenant-scoped `404` responses
- enforced server-side residence-title requirements during onboarding submission for non-exempt nationalities (title + employment authorization, and expiry when not unlimited) so clients can no longer bypass the frontend-only checks by posting `nationalities` without required residence data
- aligned passkey credential mapping with WebAuthn-lib 5.3 by using `PublicKeyCredentialSource` directly, fixing passkey challenge flows that were failing with a `TypeError` (return type mismatch)
- forced the PHPUnit `DB_CONNECTION` and `DB_DATABASE` overrides so the early PostgreSQL test bootstrap always provisions `testing*_test_*` databases instead of inheriting runtime `secpal*` names from the shell environment during parallel runs
- aligned `php-ci.yml` PostgreSQL credentials, `pg_isready` health check, and parallel database bootstrap with the working `quality.yml` `testing` / `testing_test_*` convention; local preflight now fails fast if `php-ci.yml` drifts back to the old `db` credentials
- fixed address-street and address-locality `withValidator` callbacks to use `is_scalar` guards before casting, preventing a 500 error when an array is submitted for a filter field
- fixed `AddressDataDownloader` to wrap the HTTP download and hash in a try/catch that removes the partial temp file on any exception, not just on a non-2xx response
- improved `AddressStreetCsvImporter` wrong-column-count errors with row number, expected versus observed counts, and a truncated row preview for large-file debugging
- fixed `AddressSuggestionService` to pass `postalCodePrefix` into `orderStreetResults` and use it as an exact-match relevance tiebreaker
- fixed `AddressDataImportService` pruning to expire stale `running` imports (older than 6 hours) before the delete pass, so crashed runs no longer accumulate indefinitely
- aligned onboarding residential-address-history five-year previous-address enforcement with export address-history lookback (`startOfDay` boundary) via shared `App\Support\AddressHistoryLookback::YEARS`, and expanded Pest coverage for submit validation
- fixed `ImportAddressDataCommand` to output failed results with `error()` and skipped results with `warn()` instead of always using `info()`
- gated `OnboardingDemoUserSeeder` to non-production environments so production deployments cannot accidentally expose the demo onboarding account
- fixed rollback of the residential address history onboarding step migration to only restore `onboarding_completed` for employees who previously completed onboarding (i.e., have `onboarding_completed_at` set), preventing false completion of in-progress dossiers
- added `timeout-minutes: 30` to the `test` job in `php-ci.yml` to prevent runaway CI runs
- updated `BEWACHV_COMPLIANCE.md` to accurately reflect that the migration backfills legacy flat-column addresses before dropping them
- corrected description grammar from "the date since you live there" to "the date since you have lived there" in the residential address history schema and language file
- updated SPDX copyright years to 2025-2026 in `DatabaseSeeder`, `routes/console.php`, `REUSE.toml`, and `2026_01_04` migration
- stopped Xdebug code-coverage tracking in forked child processes inside the concurrency tests so the parent worker's coverage collection is not corrupted when `XDEBUG_MODE=coverage` is active
- aligned php-ci.yml PostgreSQL service and step env to use `testing` as the initial database instead of `db`, matching the phpunit.xml-forced `DB_DATABASE=testing` so `EnvConfigSmokeTest` and other tests that do not use `RefreshDatabase` can connect to the base database in parallel runs; also corrected the pre-create step to produce `testing_test_N` worker databases instead of unused `db_test_N` names
- made local API preflight run `serial`-group tests outside the parallel Pest run, so the dedicated concurrency regressions no longer race each other by repeatedly `migrate:fresh`-ing the shared base `testing` database during `PREFLIGHT_RUN_TESTS=1`
- made the PostgreSQL test bootstrap fail fast with an explicit owner/`public`-schema permission error when an existing `testing_test_*` database is not writable by the configured app user, so local ownership drift no longer degrades into late migration failures across large parallel test runs
- fixed `OnboardingFormDataSchemaValidationService::isPropertySemanticallyEmpty` treating non-string values (e.g. integers) for `string`-type fields as empty, which previously allowed malformed payloads to bypass JSON Schema validation for optional templates; proved with a new regression test
- wrapped onboarding submission approval state updates and completion checks in one transaction, and restricted tax-identifier employee sync to the canonical `tax_identification_number` template key so approval can no longer commit partial state or mutate PII from name-matched custom templates
- aligned onboarding completion email verification with the invited employee mailbox by syncing the user login email before verification and dispatching the `Verified` event in that path; auth payloads now also expose resolved onboarding workflow state via `Employee::resolveOnboardingWorkflowStatus()`, with regression tests for login, `/v1/me`, and onboarding completion
- fixed residential-address onboarding step rollout to reopen already-completed pre-contract dossiers when the new mandatory step is inserted, preserve existing completed residential-address step state, reject blank employee address rows before they can wipe persisted history, and let address autocomplete match common ASCII fallback input such as `Muller`/`Koln` for umlauted source data
- made the employee-address migrations preserve legacy flat/current-history data during forward rollout and rollback, and tightened the residential-address-history rollback so it refuses to remove completed or approved residential-address-history steps while still removing only migration-inserted empty steps and preserving pre-existing tenant/global templates and submissions
- removed ineffective `use RuntimeException;` imports from the anonymous employee-address and residential-address-history migrations so PHP 8.4 no longer aborts Polyscope preview setup during migration discovery

### Added

- added `AddressDataSeeder` to the standard setup seeding flow (non-production environments) with an optional `ADDRESS_DATA_SETUP_SOURCE_PATH` config key for offline/local CSV-backed setup without a network download
- added OpenPLZ-backed German address reference imports, weekly refresh scheduling, and authenticated `/v1/addresses/de/*` autocomplete/status endpoints for street and locality lookup
- added optional employee `emergency_contacts` support across request validation, persistence (new nullable JSON column on `employees`), and API resource serialization, with targeted regression tests for validation, model casting, resource output, and schema presence

### Changed

- removed duplicate assertion in `OnboardingControllerTest` consent-field test
- updated `SPDX-FileCopyrightText` year in `SubmitOnboardingFormRequest`

### Changed

- updated `SetLocaleFromHeader` docblock to describe the three-step resolution order (user preferred_locale, Accept-Language header, application default); also updates SPDX years in the middleware, request, and test files

### Changed

- renamed the onboarding review and Android provisioning API surfaces from `/v1/admin/...` to neutral `/v1/onboarding-review/...` and `/v1/android-enrollment-sessions...` paths, and corrected HR lifecycle emails to link to the shipped frontend employee detail route under `FRONTEND_URL` instead of the dead `/admin/employees/...` SPA path
- removed the remaining Admin-role and `admin`-scope documentation drift from the API guides, auth examples, ADR-linked RBAC references, and supporting test fixtures so the documented bootstrap, authorization, and audit examples now consistently use explicit permissions plus explicit `manage` scopes instead of the deleted legacy model
- centralized organizational-scope access resolution in `User::hasAccessToUnit()` so persisted checks and in-memory self-lockout simulations now share the same direct-scope-first descendant semantics instead of duplicating that logic in `OrganizationalScopeController` (refs `api#982`)
- removed the predefined `Admin` role and the `admin` organizational scope access level from the API runtime, seeded fixtures, and RBAC/auth test bootstraps; privileged access is now modeled through explicit permissions plus `manage` organizational scopes, and existing `admin` scope rows are normalized to `manage` during migration
- clarified the repo-local under-`1.x` policy in Copilot governance so API work explicitly prefers removing insecure or obsolete compatibility shims over preserving them without a proven live caller
- converted all findings from the 2026-03-31 security audit to GitHub Issues (#834–#847) tracked under Epic #848
- strengthened repo-local Copilot governance for AI findings: API work now requires proof of defect before merging AI-generated fix PRs, treats green CI alone as insufficient evidence for semantic test changes, and explicitly rejects Pest file-scope mutations that bypass framework wiring
- wired the central Copilot-instructions validator into `quality.yml` so API pull requests now fail automatically when known Laravel AI-risk guardrails or generic AI-triage guidance are missing from the runtime baseline

### Removed

- removed `SECURITY_AUDIT_API_VALIDATION.md` from the repository root after converting its findings to tracked GitHub Issues
- removed stale and historical documentation: one-time PR artefacts (`PR_DESCRIPTION_DRAFT.md`), DDEV-era retrospectives and production-test reports (`ISSUE50_RETROSPECTIVE.md`, `ISSUE74_RETROSPECTIVE.md`, `PRODUCTION_TEST_PASSWORD_RESET.md`, `PRODUCTION_TEST_PHASE2_EMAIL.md`, `PR_REVIEW_ISSUE50.md`), superseded workflow guides (`EPIC_WORKFLOW.md`, `EPIC_IMPLEMENTATION_SUMMARY.md`, `SELF_REVIEW_CHECKLIST.md`), and the PHPStan workaround note (`ISSUE_PHPSTAN_SANCTUM_TYPES.md`) and obsolete reminder prompt file (`COPILOT_REMINDER_PATTERNS.md`)

### Fixed

- fixed onboarding self-service backend blockers so authenticated pre-contract employees can upload files to their own editable onboarding submissions without an extra `onboarding.write` grant, while onboarding completion now counts required tenant templates together with required system templates for the employee's tenant-specific completion state (fixes `api#986`)
- fixed `GET /v1/employees` to apply the same scoped management-level visibility rules as `GET /v1/employees/{employee}`, so collection responses no longer include employees whose detail endpoint would still return `403 This action is unauthorized.` for the same user (fixes `api#987`)
- aligned employee create, view, and update authorization on the same descendant-scope and management-level rules, so users can no longer create an employee in a scoped unit and then hit `403 This action is unauthorized.` on the immediate detail fetch; create and update now reject employees whose target unit or management level would fall outside the actor's writable, assignable, and viewable scope
- creators of newly created child organizational units now receive a direct `manage` scope on that exact unit, so they can delete it and manage its scopes immediately even when their parent access only grants `manage`
- repaired organizational hierarchy writes when an existing parent unit is missing its mandatory depth-0 self-closure row, and backfill those missing self-closures during migration so child-unit creation no longer silently stores new children as roots with `parent: null`
- restored `GET /v1/organizational-units/{organizational_unit}` after the single-unit response refactor by threading the request context into the show action as well, so live detail fetches no longer fail with `500 Internal server error` when a user opens an organizational unit directly
- made single organizational-unit create, show, update, reparent, and detach responses reload their `parent` relation under the same need-to-know filter as list responses and expose per-unit action permissions, so child-unit mutations no longer poison the frontend cache into showing descendants as roots after reload and clients can hide unauthorized organization actions consistently
- auto-grant a direct scope on child organizational units when a successful create or reparent operation would otherwise leave the acting user without descendant-based access, so newly created and newly moved child units remain visible, editable, and reload-stable instead of disappearing from `/v1/organizational-units` and failing later edits with `403 This action is unauthorized.`
- blocked users from downgrading or deleting their own last `manage` path on an organizational unit via `/v1/organizational-units/{organizational_unit}/scopes/{scope}`, so scope-management changes no longer allow accidental self-lockout from the very unit whose scopes they are editing
- applied the remaining `api#935` test and copy cleanups by extracting reused employee-controller and concurrency helpers, making BewachV retention assertions date-relative, strengthening tenant-isolation role-validation fixtures, and aligning the BWR ID-document auto-deletion email wording with its mail coverage
- made the employee lifecycle, qualification-controller, employee-observer, and serial concurrency test fixtures self-cleaning and idempotent, while `TenantSetupCommandTest` now explicitly resets leaked tenant-key state and the role-management plus temporal-role API fixtures tolerate pre-seeded `sanctum` RBAC state, so leaked permissions, roles, tenant keys, or concurrency-test rows no longer poison later full-suite runs (fixes `api#972`)
- scoped `POST /v1/organizational-units/{organizational_unit}/parent` parent validation to the authenticated tenant and excluded soft-deleted organizational units, so cross-tenant and deleted `parent_id` probes now fail validation instead of leaking existence through later `403` or `404` responses (fixes `api#966`)
- made the RBAC test bootstraps in `RoleManagementApiTest` and `UserPermissionAssignmentApiTest` seed permissions and tenant-scoped roles idempotently, so repeated or pre-seeded `sanctum` contexts no longer fail with duplicate-role or duplicate-permission exceptions during targeted validation and the full-suite rerun for `api#938`
- restored pre-contract onboarding self-service access for the authenticated employee flow so `GET /v1/onboarding/steps`, `/v1/onboarding/templates*`, and `/v1/onboarding/submissions` plus `POST`/`PATCH /v1/onboarding/submissions*` no longer require extra `onboarding.read` or `onboarding.write` runtime permissions beyond the pre-contract ownership checks already enforced by the onboarding policies and controller guards (fixes `api#954`)
- upgraded `phpunit/phpunit` to `12.5.23` together with `pestphp/pest` `4.6.3` so the test runner is no longer pinned to a vulnerable PHPUnit child-process INI forwarding release range (`GHSA-qrr6-mg7r-m243`)
- blocked direct `POST /v1/employees` and `PATCH /v1/employees/{employee}` writes to `employment_end_date` and `retention_period_end` so BewachV / GDPR retention dates remain lifecycle-managed by termination handling and observer-driven calculation instead of client-controlled payloads (continues `api#470`)
- fixed `GET /v1/employees/compliance-alerts` to filter the full alert set before paginating, so warning/critical/expired work-permit and certification entries are no longer hidden behind non-alert employees and the pagination metadata now reports the real alert count (continues `api#472`)
- blocked `POST /v1/customers/{customer}/assignments` when the target user is linked to an employee with expired or critical compliance documents, so customer-level assignment creation now matches the existing site-assignment safety gate and returns the same `blocking_documents` payload for expiring certifications (fixes `api#997`, continues `api#872`)
- made `POST /v1/employees` accept omitted `management_level` values for non-management hires again, defaulting persisted records to `0` so invite-enabled onboarding preparation no longer fails with `422 The management level field is required.` when clients do not send a leadership rank
- fixed non-management employee create/update scope validation for organizational scopes that store rank maxima as `NULL`, so `management_level = 0` remains viewable and assignable for unrestricted scopes instead of failing with the leadership-range validation error; the corresponding create/update validation messages are now also present in the checked-in English and German gettext catalogs
- blocked direct `PATCH /v1/employees/{employee}` writes to `bwr_status`, `bwr_id`, `bwr_notes`, and `bwr_registered_at` so BWR transitions and their audit trail must go through the dedicated `PUT /v1/employees/{employee}/bwr/status` endpoint (fixes `api#881`)
- centralized employee compliance alert status values in `EmployeeComplianceService` and reused those constants in `IndexEmployeeRequest` validation/messages so filter rules stay synchronized with compliance business logic
- added dedicated index request validation for qualification, organizational-unit, customer-assignment, and site-assignment filters so invalid category, type, parent, and role query values now fail fast with `422` responses instead of silently producing empty or ambiguous results

- normalized unexpected API exceptions behind a final JSON catch-all so `/v1/*` requests now return a stable `message` payload even with `APP_DEBUG=true`, while 500 responses always use `Internal server error.` instead of leaking stack traces or internal exception details

- normalized generic API `404` responses to `{"message":"Resource not found."}` for unknown `/v1/*` routes in all debug modes, preventing default Laravel HTML or stack-trace payloads from leaking through

- escaped `\\`, `%`, and `_` in customer, site, employee, and activity-log search terms through a shared LIKE-pattern helper so wildcard-only and backslash-escaped search input no longer expands into broad `LIKE` / `ILIKE` scans or drift between endpoints

- capped `/v1/organizational-units` pagination with a dedicated index request so oversized `per_page` values are rejected with `422` instead of allowing unbounded result windows

- switched onboarding submission rejection to persist the validated `reason` payload instead of re-reading raw request input after inline validation

- restricted regulated employee identifiers in `EmployeeResource` behind a new `employees.read_sensitive` permission, seeded a dedicated `HR` role for that access, and stopped non-HR viewers with ordinary employee read access from receiving decrypted tax, social-security, permit, health-insurance, ID-document, and Sachkunde identifier fields

- verified employee email uniqueness enforcement with regression coverage against the unique plaintext `employees.email` column used by `StoreEmployeeRequest`

- added a dedicated `health` rate limiter for `/health`, `/health/live`, and `/health/ready` so unauthenticated health probes now return `429` after repeated abuse from the same IP and route bucket

- switched role and permission management writes to validated request payloads so those controllers no longer read raw input after form-request validation
- scoped role-name uniqueness validation to the active tenant plus `sanctum` guard so different tenants can reuse the same role names without tripping false `422` conflicts on create or update
- reduced the public health surface by removing the `/health` version field and by limiting `/health/ready` responses to the readiness status plus timestamp instead of exposing database, key-management, scheduler, and queue-worker details
- standardized API V1 delete endpoints on `response()->noContent()` so successful `204` responses are implemented consistently across employee, document, qualification, assignment, customer, site, organizational-unit, and cost-center deletes
- serialized employee number generation per tenant inside the employee create transaction, locking the tenant row plus the current-year employee number lookup so concurrent `POST /v1/employees` requests cannot derive duplicate `employee_number` values; employee numbers are now also enforced by a tenant-scoped `(tenant_id, employee_number)` unique constraint so different tenants can safely reuse the same yearly sequence without cross-tenant collisions
- serialized customer and site number generation per tenant inside the respective create transactions, locking the tenant row plus the current-year number lookups so concurrent `POST /v1/customers` and `POST /v1/sites` requests cannot derive duplicate `customer_number` or `site_number` values; the existing tenant-scoped unique constraints remain in place as a last-resort safeguard (fixes SecPal/api#858)
- fixed `buildUserAuthorizationData` returning empty roles and permissions on authentication routes (login, passkey verify, MFA verify) because the global `InjectTenantId` middleware cannot resolve a user before authentication completes, leaving the Spatie PermissionRegistrar with a null team context; the method now explicitly sets the team from the user's `tenant_id` before eager-loading (fixes SecPal/frontend#822)
- fixed the passkey browser-session model mismatch by keeping registration compatibility-friendly (`resident_key: preferred`) while omitting deprecated `require_resident_key` when it is false, and by letting `/v1/auth/passkeys/challenges` take an optional email address that returns `allow_credentials` for email-scoped fallback sign-in when discoverable credentials are unavailable in the browser or authenticator
- aligned Android provisioning QR payload download URLs with the per-session `apk.secpal.app/android/channels/{channel}/app.secpal-latest.apk` endpoint model by computing the URL unconditionally from `android.artifact_base_url` + `update_channel`; removed the obsolete `android.package_download_url` config key whose hardcoded `managed_device` default was silently overriding the per-channel computation for all other channels
- fixed passkey registration options sending `authenticator_attachment: null` and `rp.icon: null` from the webauthn-lib serializer; browsers coerce JSON null to the DOMString `"null"` which is not a valid `AuthenticatorAttachment` enum value, preventing `navigator.credentials.create()` from showing the WebAuthn dialog; the API now strips null values from `authenticator_selection` and `rp`, and omits empty `exclude_credentials` arrays
- fixed passkey registration and login verification failing with "Undefined array key clientDataJSON" because `Str::camel('client_data_json')` produces `clientDataJson` (lowercase json) while the webauthn-lib denormalizer expects `clientDataJSON` (uppercase JSON); added an explicit override map in `PasskeyService::keysToCamelCase()` with targeted unit tests
- fixed passkey login challenge returning an empty `allow_credentials` array that caused browsers to reject the WebAuthn discoverable-credential flow with "Resident credentials or empty `allowCredentials` lists are not supported"; the API now omits the field when empty so browsers correctly trigger the resident-key prompt
- fixed native Android passkey verification rejecting SecPal's official app origin with `Invalid origin. Not in the list of allowed origins.` by deriving and allowing the canonical `android:apk-key-hash:` origin from the Android signing certificate fingerprint, with an env override for non-canonical signing keys
- improved TOTP anti-replay UX: recovery-code regeneration and MFA disablement now return a specific "code was already used recently" validation message instead of a generic "invalid code" when the submitted TOTP code was consumed by a recent action in the same time window

### Added

- added native token-mode passkey sign-in endpoints at `POST /v1/auth/token/passkeys/challenges` and `POST /v1/auth/token/passkeys/challenges/{challengeId}/verify`, so Android and other native clients can complete WebAuthn passkey login without falling back to the browser-session auth model
- added user, admin, and legal guides for the shipped activity logging system, covering scoped log visibility, verification semantics, retention execution, and the GDPR-oriented archive model (api#892)
- introduced stable `template_key` column on `onboarding_form_templates` so `OnboardingSchemaLocalizationService` uses the immutable key for translation lookup instead of deriving it from the mutable `name` field; system templates are seeded with their keys; tenant templates without a key fall back to the existing `Str::snake($name)` derivation (fixes api#832)
- added the first `api#472` non-EU work-permit core slice: encrypted `work_permit_number` storage, richer work-permit types, conditional non-EU validation, work-authorization and expiring-document computed fields, factory states for permit-bearing employees, and GDPR-driven work-permit copy deletion when BWR approval or permanent authorization makes the copy unnecessary
- added the `api#869` employee certification-expiry core slice: encrypted firearms-license storage, first-aid/fire-safety/evacuation tracking fields, structured `additional_certifications`, request validation, factory coverage, and expanded `expiring_documents` aggregation for soon-to-expire or expired compliance records
- added the first `api#872` operational certification integration slice: `GET /v1/employees/compliance-alerts` for HR/compliance overview filtering and automatic site-assignment blocking when a linked employee has expired or critical compliance documents
- added the `api#875` employee compliance notification slice: daily milestone emails for warning (30 days), critical (7 days), and first-day expired compliance documents, including a dedicated queued mailable and scheduler hook
- added the first `api#877` BWR export backend slice: CSV export generation for BWR-ready employees, pending-state transition on export, and secure download targets for generated BWR export files
- added the `api#879` BWR status backend slice: dedicated employee BWR status updates with activation-time BWR-ID persistence, automatic registration timestamping, and audited status-change logging that triggers the existing BWR-active observer cleanup flow
- added the `api#882` BWR XML export backend slice: explicit export-format validation, XML file generation via the existing BWR export endpoint, and format-aware download responses for generated XML exports
- added the `api#884` BWR export audit metadata slice: generated export file sizes are now captured in service metadata and persisted alongside file paths in the BWR export activity log
- added the `api#912` BWR HR notification slice: when BWR activation actually auto-deletes an employee ID-document copy, SecPal now queues a dedicated HR alert email with the employee context and GDPR storage-limitation rationale
- added the `api#471` BWR workflow proof slice: documented the manual authority-submission workflow in the API README and added end-to-end regression coverage for export -> pending -> active, including download, audit-log, ID-copy deletion, and HR notification side effects
- added localized onboarding template schema responses for `/v1/onboarding/templates*`, including backend-managed English/German labels, descriptions, and enum display names with `preferred_locale` / `Accept-Language` fallback handling, so onboarding form text no longer needs to be frontend-owned
- added the `employees:delete-expired` retention command for BewachV §21 / GDPR employee erasure, including tenant-scoped dry-run support, hard deletion of expired terminated employee records, local storage cleanup for employee and onboarding uploads, linked-user anonymization, and a daily scheduler hook after activity retention processing
- Added the initial Android enrollment API slice for Epic SecPal/.github#327, including tenant-bound enrollment session storage, private QR/bootstrap token issuance, admin create/list/read/revoke endpoints, a public bootstrap exchange endpoint, and audited provisioning lifecycle events
- added `PATCH /v1/onboarding/submissions/{submission}` so authenticated pre-contract employees can update their own draft or rejected onboarding submissions by id without resending `form_template_id`; omitting `status` on a rejected submission now defaults to draft; state-specific 409 messages are returned for submitted and approved submissions; and a pre-contract employee guard is enforced for consistency with the existing upsert path
- added the phase-2 passkey backend foundation with WebAuthn-backed browser sign-in and self-service passkey management endpoints, persisted passkey credentials plus challenge state, and regression coverage for session establishment, validation failures, and throttled invalid passkey verification attempts
- added a policy-protected admin MFA reset path at `DELETE /v1/users/{user}/mfa`, dedicated `users.reset_mfa` permission seeding, explicit MFA reset throttling, and authentication audit entries for MFA enable/disable, recovery-code regeneration, recovery-code depletion, and admin-triggered resets
- Added the MFA phase-1 backend foundation by integrating `laragear/two-factor`, publishing a UUID-safe `two_factor_authentications` migration, wiring the `User` model into the package contract, and covering enrollment, recovery-code rotation, and disablement lifecycle behavior with focused tests
- Added the phase-1 MFA API endpoints for login challenges, TOTP enrollment confirmation, `/me/mfa` status, recovery-code regeneration, and authenticated MFA disablement so the frontend can build against real SecPal API behavior instead of only the contract
- security audit document (`SECURITY_AUDIT_API_VALIDATION.md`) covering API validation, error handling, and request semantics with 3 HIGH, 6 MEDIUM, 5 LOW findings and 3 best-practice recommendations; includes prioritized fix order and negative test ideas
- added `POST /v1/onboarding/submissions/{submission}/files` endpoint for pre-contract employee dossier uploads, storing encrypted attachment blobs in tenant-scoped local storage, restricting uploads to the authenticated owner's own `draft` or `rejected` submission, and returning the uploaded file metadata expected by the onboarding frontend

### Changed

- corrected the Composer package metadata license from the inherited Laravel skeleton `MIT` value to `AGPL-3.0-or-later` so repository metadata matches the actual SecPal API licensing
- strengthened Copilot governance: require test-impact analysis and same-commit test updates when a fix alters observable behavior, explicitly recommend `PREFLIGHT_RUN_TESTS=1` for behavioral or security changes, and mandate `--body-file` for GitHub CLI (`gh pr create` or `gh pr edit`) to prevent shell escaping issues
- added a behavior-change reminder to the preflight skip-tests hint so the pre-push hook explicitly warns about enabling tests for security or state-lifecycle fixes
- Replaced the API Translation.io workflow with repo-native Polyglot-managed PO/Gettext catalogs, added a dedicated production blocker for the Polyglot web UI, and moved translated mail key subjects into checked-in language files so API translation maintenance now stays local and POedit-friendly.
- clarified the repo-local branch-start and post-merge readiness workflow so new API work must start from a clean, updated local `main`, and post-merge cleanup now explicitly returns the repo to `main`, refreshes dependencies where applicable, runs a suitable readiness command, and confirms a clean working tree

### Fixed

- moved `TestCaseBootstrapEnvironmentProbe` into `tests/Support` and updated its bootstrap-file regression tests so Composer no longer emits a PSR-4 autoload warning for the test helper class
- invalidate passkey registration challenges on verification failure so failed attempts cannot be replayed; the authentication challenge path was already fixed in an earlier PR but the registration path was missed
- Replaced 12 direct `new App\...` instantiations in test files with `app(ClassName::class)` container resolution so tests remain correct if those classes gain constructor dependencies
- Added `incrementTestKekCounter()` to all 84 remaining test `beforeEach` hooks that use `getTestKekPath()`, preventing KEK file path collisions during parallel test execution with Paratest
- Fixed parallel-test KEK file conflicts in `EmployeeTest` by calling `incrementTestKekCounter()` in the `beforeEach` hook before setting the KEK path
- Fixed `EmployeeObserver` instantiation in `EmployeeTest` to use the service container (`app()`) instead of direct `new` to ensure proper dependency injection
- removed duplicate `BitcoinBlockHeaderAttestation` import in `scripts/ots-verify.py`, improved the `ImportError` message with actionable upgrade instructions, added per-iteration `X-RateLimit-Remaining` assertions to passkey rate-limit loops, introduced `PasskeyCredentialFactory` to replace bare unsaved model instances in tests, added authentication challenge cleanup on failed verification (controller and test), switched `passkey-verify` throttle key to IP-only so accumulated failures across fresh challenges still reach the rate limit after the `forgetAuthenticationChallenge` security fix, added `@use HasFactory<PasskeyCredentialFactory>` PHPStan generic annotation, added new test for passkey registration without a pre-existing credential, validated encrypted blob disk structure in the onboarding file-upload test, added `Spatie\Activitylog\Models\Activity` use-statement, extracted `TEST_KEK_BASE_PATH` and `SPA_XSRF_COOKIE_NAME` constants, improved error handling for KEK file deletion and CSRF cookie failures, extracted `getLoginRateLimiterKeys()` helper, and scoped `Employee::withoutEvents()` to individual tests instead of globally disabling the event dispatcher
- exposed the login rate-limit response headers via CORS on SPA auth requests, so the browser frontend can read `Retry-After` and `X-RateLimit-*` directly from `/v1/auth/login` instead of falling back to a speculative local lockout timer
- hardened the pre-auth login lockout for `/v1/auth/login` and `/v1/auth/token` by enforcing the wrong-password throttle on both a shared account bucket and an IP-plus-account bucket, keeping MFA challenge issuance on valid passwords unchanged while making temporary `429` lockouts reproducible even when browser sessions or apparent client IPs change
- aligned the scheduled `employees:update-status` activation query and related lifecycle/onboarding regressions with the explicit `ready_for_activation` workflow gate, so daily activation no longer attempts stale pre-contract records and the affected API tests reflect the same readiness rule as the lifecycle service
- centralized the pre-contract onboarding workflow transition rules in the employee model, moved onboarding write paths and lifecycle activation onto that shared state machine, and blocked `POST /v1/employees/{employee}/activate` until onboarding is both dossier-complete and explicitly in `ready_for_activation`, so backend activation no longer treats `onboarding_completed` alone as sufficient readiness for internal access
- added an explicit HR/compliance onboarding confirmation action for pre-contract employees, including dedicated `onboarding.confirm` authorization, auditable confirmation events, and automatic promotion from `contract_confirmed` to `ready_for_activation` when the contract start gate is already satisfied
- added explicit `return False` to the `except` block in `scripts/ots-verify.py`'s `verify_proof()` to eliminate the implicit `None` fall-through return and satisfy the CodeQL "explicit vs implicit returns" rule
- made the `/v1/auth/login`, `/v1/auth/token`, and pending MFA challenge throttles count only real invalid-credential or invalid-code failures while preserving Laravel's `Retry-After` and `X-RateLimit-*` headers on custom `429` JSON responses, so correct primary logins that legitimately transition into MFA no longer burn the wrong-password lockout bucket
- invalidated pending MFA login challenges on failed verification and re-keyed the `mfa-challenge` throttle to IP plus route scope, so repeated bad MFA codes still accumulate across freshly reissued challenges instead of resetting with each consumed challenge ID
- normalized MFA recovery-code verification input by stripping presentation separators and uppercasing grouped entries before comparison, and added regression coverage that keeps the canonical API payload shape at raw 8-character uppercase alphanumeric codes
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
    - **Identity Data**: `gender` (mandatory for BWR), `birth_name_enc`, `previous_names` (JSON), `birth_city`, `birth_country` (ISO 3166-1 Geburtsland)
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
