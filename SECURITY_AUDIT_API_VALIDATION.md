<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# Security Audit: API Validation, Error Handling and Request Semantics

**Date:** 2026-03-31
**Scope:** `api/` — Laravel 13 backend, all HTTP endpoints
**Method:** Static code review of all controllers, form requests, middleware, models, exception handlers

---

## Summary

| Severity      | Count |
| ------------- | ----- |
| HIGH          | 3     |
| MEDIUM        | 6     |
| LOW           | 5     |
| BEST PRACTICE | 3     |

---

## HIGH — Confirmed Findings

### H-1: Race condition in `generateEmployeeNumber()` — duplicates possible

**Severity:** HIGH
**Affected file:** `app/Http/Controllers/Api/V1/EmployeeController.php` lines 267–290
**Category:** Data integrity / Concurrency

**Finding:**
`generateEmployeeNumber()` reads the highest employee number, increments it, and creates the record — without a lock or transaction. Under concurrent requests (e.g. batch import via UI or API tooling) two requests can read the same number and generate duplicates.

```php
$latestEmployee = Employee::where('tenant_id', $tenantId)
    ->where('employee_number', 'like', "{$prefix}%")
    ->orderBy('employee_number', 'desc')
    ->first();
// No lock between read and write
```

No `UNIQUE` constraint on `(tenant_id, employee_number)` is visible in the controller logic (must be verified in migrations).

**Negative test idea:**
Two concurrent `POST /v1/employees` requests with identical timing — expect: one gets a conflict or they receive different numbers.

**Fix:**

```php
return DB::transaction(function () use ($tenantId) {
    $year = now()->year;
    $prefix = "EMP-{$year}-";
    $latestEmployee = Employee::where('tenant_id', $tenantId)
        ->where('employee_number', 'like', "{$prefix}%")
        ->orderBy('employee_number', 'desc')
        ->lockForUpdate()
        ->first();
    // ...rest
});
```

Plus: add `UNIQUE(tenant_id, employee_number)` migration if not already present.

---

### H-2: Missing `per_page` cap on multiple controllers — DoS vector

**Severity:** HIGH
**Affected files:**

- `app/Http/Controllers/Api/V1/OrganizationalUnitController.php` line 92: `$request->integer('per_page', 15)` — no validation
- `app/Http/Controllers/Api/V1/CustomerController.php`: same pattern (plain `Request`, no bounds)

Note: `SiteController` already uses `IndexSiteRequest` with `per_page` max:100 — it is **not affected**.

**Finding:**
`EmployeeController::index()`, `ActivityLogController::index()`, and `SiteController::index()` correctly use dedicated form requests with `'per_page' => ['nullable', 'integer', 'min:1', 'max:100']`. The controllers listed above use plain `Request` and accept arbitrary values.

An attacker can send `?per_page=999999` causing OOM/timeout, especially on endpoints with eager-loading.

**Negative test idea:**
`GET /v1/customers?per_page=100000` — expect: 422 instead of full database dump.

**Fix:**
Create `IndexCustomerRequest`, `IndexOrganizationalUnitRequest` with `per_page` validation (`min:1, max:100`). Or apply a generic clamp:

```php
$perPage = min($request->integer('per_page', 15), 100);
```

---

### H-3: Unvalidated filter parameters in query filters — LIKE wildcard injection and semantic risks

**Severity:** HIGH (LIKE wildcard injection + unvalidated WHERE clause values)
**Affected files:**

| Controller                     | Parameter       | Line | Problem                                       |
| ------------------------------ | --------------- | ---- | --------------------------------------------- |
| `QualificationController`      | `category`      | 61   | No enum/whitelist — arbitrary string in WHERE |
| `OrganizationalUnitController` | `type`          | 60   | No enum — arbitrary string in WHERE           |
| `OrganizationalUnitController` | `parent_id`     | 65   | Format not validated (no UUID check)          |
| `CustomerAssignmentController` | `role` (filter) | 51   | Arbitrary string, not sanitized               |
| `SiteAssignmentController`     | `role` (filter) | 51   | Arbitrary string, not sanitized               |

Note: `SiteController` uses `IndexSiteRequest` which validates `type` (enum), `customer_id` (UUID), and `organizational_unit_id` (UUID) — it is **not affected**.

Laravel's Query Builder uses prepared statements, so **direct SQL injection is not possible**. The actual risks are:

1. **LIKE wildcard DoS**: Search fields in `EmployeeController` and `CustomerController` accept `%` and `_` as wildcards. Input like `%%%%%%%%%` on tables with encrypted fields can trigger expensive full-table scans. (`SiteController` search is validated via `IndexSiteRequest` max:255 but LIKE wildcards are still not escaped.)
2. **Semantically incorrect results**: Unvalidated `type` or `category` values produce empty results without an error — no crash, but poor API semantics.
3. **UUID bypass**: Non-UUID values for `customer_id` or `organizational_unit_id` go directly into WHERE, returning zero results instead of 422.

**Negative test ideas:**

- `GET /v1/qualifications?category='; DROP TABLE--` — expect: 422 or empty array, no crash
- `GET /v1/sites?per_page=50&search=%25%25%25%25%25` — measure response time
- `GET /v1/organizational-units?type=nonexistent_type` — expect: 422 with error message

**Fix:**
Create dedicated form request classes for index endpoints with:

- Enum validation for `type`/`category`
- UUID validation for foreign-key filters
- LIKE escaping for search fields: `str_replace(['%', '_'], ['\\%', '\\_'], $search)`

---

## MEDIUM — Confirmed Findings

### M-1: `PersonController::byEmail()` — missing email format validation

**Severity:** MEDIUM
**Affected file:** `app/Http/Controllers/PersonController.php` lines 71–85
**Category:** Validation

**Finding:**
The `email` query parameter is only checked for non-empty, but not validated as an email format. The search uses a blind index (HMAC), so a functional error is unlikely. However, any arbitrary string is passed to `PersonRepository::findByEmail()` — the HMAC is computed over the raw value, but there is no 422 error for obviously invalid input.

```php
$email = $request->query('email');
if (! $email) { /* 400 */ }
// No format check
$person = $this->repository->findByEmail($tenantId, $email);
```

**Fix:**

```php
$email = $request->query('email');
if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return response()->json([
        'message' => __('A valid email address is required'),
    ], Response::HTTP_BAD_REQUEST);
}
```

---

### M-2: Inconsistent error semantics in `passwordReset()` — email enumeration via timing

**Severity:** MEDIUM
**Affected file:** `app/Http/Controllers/AuthController.php` lines 282–337
**Category:** Information Disclosure

**Finding:**
`passwordResetRequest()` correctly always returns 200 (anti-enumeration). But `passwordReset()` has different response timing signatures:

- User does not exist → immediate 400 (no DB lookup for token)
- User exists, token wrong → hash comparison + 400
- User exists, token expired → timestamp check + token deletion + 400

The timing differences are measurable and allow email enumeration via the reset endpoint.

**Plausible risk:** An attacker can verify which accounts exist.

**Negative test idea:**
Timing analysis: 100x `POST /v1/auth/password/reset` with known vs. unknown email, random token field — statistically significant difference in response times?

**Fix:**
Unify all three error paths — always look up the token record and compare hashes, even when the user does not exist (use a dummy hash). Or: process all error cases with identical logic depth.

---

### M-3: Health endpoints leak infrastructure details

**Severity:** MEDIUM
**Affected file:** `app/Http/Controllers/HealthController.php` + `routes/api.php` line 44
**Category:** Information Disclosure

**Finding:**
Three health endpoints are **unauthenticated**:

- `GET /health` → returns `service: "SecPal API"` and `version: "1.0.0"`
- `GET /health/live` → returns timestamp
- `GET /health/ready` → returns status of `database`, `tenant_keys`, `kek_file`

The `/health/ready` endpoint reveals:

1. That PostgreSQL is used as the database (on error)
2. Whether tenant keys exist (multi-tenant architecture)
3. Whether the KEK file is present (envelope encryption architecture)

**Fix:**

- `/health` and `/health/live` remain public (Kubernetes probes)
- Put `/health/ready` behind `auth:sanctum` or an API key; alternatively, only return details for authenticated callers
- Remove version from `/health` response (fingerprinting vector)

---

### M-4: `EmployeeResource` returns all sensitive fields to every authorized viewer

**Severity:** MEDIUM
**Affected file:** `app/Http/Resources/EmployeeResource.php`
**Category:** Information Disclosure / Principle of Least Privilege

**Finding:**
`EmployeeResource::toArray()` returns **all** decrypted fields to every authorized viewer — including:

- `tax_id`
- `social_security_number`
- `id_document_number`
- `health_insurance_number`
- `work_permit_number`, `residence_permit_number`
- `sachkunde_ihk_number`

A user with `employees.read` permission (e.g. shift supervisor) sees the same data as HR.

**Plausible risk:** Over-exposure of sensitive data to legitimately authorized but non-HR users.

**Fix:**
Use conditional fields in the resource based on permission:

```php
'tax_id' => $this->when($request->user()?->can('employees.read_sensitive'), $this->tax_id),
'social_security_number' => $this->when($request->user()?->can('employees.read_sensitive'), $this->social_security_number),
```

---

### M-5: Missing `NotFoundHttpException` coverage for generic 404s

**Severity:** MEDIUM
**Affected file:** `bootstrap/app.php` lines 84–97
**Category:** Information Disclosure

**Finding:**
The exception handler catches `NotFoundHttpException` only when `getPrevious()` is a `ModelNotFoundException`. Generic 404s (wrong URL like `GET /v1/nonexistent`) are **not** caught by the handler and fall through to Laravel's default handler. With `APP_DEBUG=true` (development), this delivers the full stack trace. With `APP_DEBUG=false`, a generic HTML or framework-dependent JSON body is returned.

```php
$exceptions->render(function (NotFoundHttpException $e, ...) {
    // ...
    if (! $isModelNotFound) {
        return null;  // ← Falls through to default handler
    }
    return response()->json(['message' => 'Resource not found.'], 404);
});
```

**Negative test idea:**
`GET /v1/this-does-not-exist` with `APP_DEBUG=true` in `.env` — expect: 404 JSON, **may receive**: stack trace or HTML

**Fix:**
Add a generic 404 JSON handler:

```php
$exceptions->render(function (NotFoundHttpException $e, Request $request) use ($shouldRenderApiJson) {
    if (! $shouldRenderApiJson($request)) {
        return null;
    }
    return response()->json(['message' => 'Resource not found.'], 404);
});
```

---

### M-6: `OnboardingController::rejectSubmission()` uses disjoint validation + `$request->input()`

**Severity:** MEDIUM
**Affected file:** `app/Http/Controllers/Api/V1/OnboardingController.php` line 570
**Category:** Validation / Code consistency

**Finding:**
The method validates `reason` inline with `$request->validate()`, but then uses `$request->input('reason')` for the DB operation instead of `$validated['reason']`:

```php
$request->validate([
    'reason' => ['required', 'string', 'max:1000'],
]);
// ...
$submission->update([
    // ...
    'review_notes' => $request->input('reason'),  // ← should use $validated
]);
```

Functionally no difference (input is already validated), but the pattern is inconsistent with the rest of the codebase which prefers `$request->validated()`. In a future refactoring round, the inline validation could be removed without noticing the unsanitized usage.

**Fix:**

```php
$validated = $request->validate([
    'reason' => ['required', 'string', 'max:1000'],
]);
// ...
'review_notes' => $validated['reason'],
```

---

## LOW — Confirmed Findings

### L-1: `RoleManagementController` and `PermissionManagementController` use `$request->input()` instead of validated data

**Severity:** LOW
**Affected files:**

- `app/Http/Controllers/Api/V1/RoleManagementController.php` lines 52, 56, 102, 109
- `app/Http/Controllers/Api/V1/PermissionManagementController.php` lines 62, 65, 116

**Finding:**
Both controllers use `CreateRoleRequest`/`UpdateRoleRequest` as form requests (validation runs), but access data via `$request->input()` instead of `$request->validated()`. Functionally correct because the form requests perform validation, but the pattern deviates from codebase convention and prevents extra fields from being automatically filtered.

**Fix:**
Consistently use `$request->validated()`.

---

### L-2: `logoutAll()` deletes only API tokens, not remember token

**Severity:** LOW
**Affected file:** `app/Http/Controllers/AuthController.php` lines 182–189
**Category:** Incorrect semantics

**Finding:**

```php
public function logoutAll(Request $request): JsonResponse
{
    $user = $request->user();
    $user->tokens()->delete();  // Only Personal Access Tokens
    // Missing: $user->forceFill(['remember_token' => null])->save();
}
```

"Log out all" deletes only Sanctum tokens, not the session remember token. A session authenticated via remember token remains active.

**Fix:**

```php
$user->tokens()->delete();
$user->forceFill(['remember_token' => null])->save();
```

---

### L-3: `employee.email` unique constraint on potentially encrypted column

**Severity:** LOW
**Affected file:** `app/Http/Requests/StoreEmployeeRequest.php` line 40
**Category:** Validation / Encryption

**Finding:**

```php
'email' => ['required', 'email', 'unique:employees,email'],
```

The `unique` validation targets the `email` column. If this is the encrypted column `email_enc`, the comparison will never match (encrypted values are never equal). If it is a plaintext field or blind index, it works correctly. Must be verified at the DB schema level.

**Negative test idea:**
Two `POST /v1/employees` with identical email — expect: 422 "already taken", not a duplicate record.

---

### L-4: `destroy()` endpoints return inconsistent response types

**Severity:** LOW
**Affected files:**

- `EmployeeController::destroy()` → `JsonResponse` with 204 (body: `null`)
- `SiteAssignmentController::destroy()` → `JsonResponse` with 204 (body: `null`)
- `PermissionManagementController::destroy()` → `Response` via `response()->noContent()`

**Finding:**
Some controllers return `response()->json(null, 204)` (sends `null` body with `Content-Type: application/json`), others use `response()->noContent()` (empty body without Content-Type). Semantically inconsistent.

**Fix:**
Standardize to `response()->noContent()` for all 204 responses.

---

### L-5: Missing rate limiting on unauthenticated health endpoints

**Severity:** LOW
**Affected endpoints:**

- `GET /health`, `GET /health/live`, `GET /health/ready` — no rate limit
- `GET /v1/onboarding/validate-token` — has `throttle:onboarding-validate` ✓
- `POST /v1/onboarding/complete` — has `throttle:onboarding-complete` ✓

**Finding:**
Health endpoints have no rate limiting. For external-facing deployments this can be abused for monitoring/probing.

**Fix:**

```php
Route::middleware('throttle:health')->group(function () {
    Route::get('/health/live', [HealthController::class, 'live']);
    Route::get('/health/ready', [HealthController::class, 'ready']);
});
```

---

## BEST PRACTICE — Improvement Recommendations

### BP-1: Dedicated form requests for all index endpoints

Several `index()` methods accept `Request` instead of a dedicated `IndexXxxRequest`:

- `QualificationController::index(Request $request)`
- `CustomerController::index(Request $request)`
- `OrganizationalUnitController::index(Request $request)`
- `CustomerAssignmentController::index(Request $request, Customer)`
- `SiteAssignmentController::index(Request $request, Site)`

Note: `SiteController` already uses `IndexSiteRequest` with full filter validation.

**Recommendation:** Create dedicated form request classes with whitelist validation for all query parameters (filter, sorting, pagination). This systematically prevents H-2 and H-3.

---

### BP-2: Global JSON exception handler for all unhandled exceptions

The current handler catches only `AuthenticationException`, `ModelNotFoundException`, and partially `NotFoundHttpException`. All other exceptions (e.g. `QueryException`, `RuntimeException`, `\Throwable`) fall through to Laravel's default handler.

**Recommendation:**

```php
$exceptions->render(function (\Throwable $e, Request $request) use ($shouldRenderApiJson) {
    if (! $shouldRenderApiJson($request)) {
        return null;
    }
    $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
    return response()->json([
        'message' => $status >= 500 ? 'Internal server error.' : $e->getMessage(),
    ], $status);
});
```

This prevents HTML error pages and stack trace leaks for API routes.

---

### BP-3: Centralize LIKE escaping for search fields

Three controllers use LIKE search without wildcard escaping:

- `EmployeeController::index()` lines 73–74: `'like', "%{$search}%"`
- `SiteController::index()` line 98: `'ilike', "%{$search}%"` (length-validated via `IndexSiteRequest`, but wildcards not escaped)
- `CustomerController::index()` line 106: `'ilike', "%{$search}%"`

**Recommendation:** Create a helper or macro for safe LIKE escaping:

```php
// In a trait or helper
public static function escapeLike(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

// Usage:
$q->where('name', 'ilike', '%' . self::escapeLike($search) . '%');
```

---

## Negative Findings (No Problem)

### ✓ InjectTenantId prevents cross-tenant attacks

`InjectTenantId` **always** strips client-provided `tenant_id` values from request and query and replaces them with the authenticated user's value. Usage of `$request->input('tenant_id')` in controllers is therefore safe — it always contains the middleware-injected value.

### ✓ Mass assignment via controllers

Although models like `Employee`, `Customer`, `Site` have many sensitive fields in `$fillable` (e.g. `tenant_id`, `status`, `user_id`), all relevant controllers use `$request->validated()`. The risk can only materialize through forgotten validation in future code — currently it is correctly protected.

### ✓ CSRF protection correctly configured

The token endpoint (`v1/auth/token`) is correctly excluded from CSRF (mobile apps). All browser endpoints have CSRF protection via Sanctum.

### ✓ Password reset tokens are hashed and time-limited

Tokens are stored with `Hash::make()`, are valid for 60 minutes, and are deleted after use.

### ✓ Localization correctly validated

`SetLocaleFromHeader` uses `$request->getPreferredLanguage(['en', 'de'])` — a Symfony method that filters against the whitelist.

---

## Frontend–Backend Alignment Check

| Aspect                  | Backend                                              | Frontend                                             | Status             |
| ----------------------- | ---------------------------------------------------- | ---------------------------------------------------- | ------------------ |
| Validation error format | `{message, errors: {field: [messages]}}`             | Parses `error.errors` as `Record<string, string[]>`  | ✓ Aligned          |
| Auth response           | `{user: {id, name, email, roles, permissions, ...}}` | Expects identical structure                          | ✓ Aligned          |
| Pagination meta         | `{current_page, last_page, per_page, total}`         | Expects `meta.*`                                     | ✓ Aligned          |
| Employee list type      | 160+ fields in `EmployeeResource`                    | Frontend `Employee` interface has ~20 fields         | ⚠️ Mismatch        |
| Error status handling   | 401→JSON, 404→JSON (partial), 422→JSON, 500→?        | 401→logout, 419→retry, 422→field errors, 500→generic | ⚠️ 500 may be HTML |

**Critical alignment finding:**
The frontend expects JSON for all API errors. The backend handler may deliver HTML for unhandled 500 exceptions (depending on `APP_DEBUG` and the default handler). `ForceJsonResponse` sets `Accept: application/json`, but Laravel's exception handler can still deliver HTML for certain boot-level errors.

---

## Prioritized Fix Order

1. **H-1**: Fix `generateEmployeeNumber()` race condition with `lockForUpdate()` + DB unique constraint
2. **H-2**: Add `per_page` cap for CustomerController and OrganizationalUnitController
3. **H-3** + **BP-1**: Create dedicated index form requests with filter validation for all list endpoints
4. **M-5** + **BP-2**: Global JSON exception handler for all 404/500 on API routes
5. **M-3**: Secure health-ready endpoint or hide details
6. **M-4**: Field-level permissions in EmployeeResource
7. **M-2**: Eliminate password-reset timing attacks
8. **M-1**: Email validation in PersonController::byEmail()
9. **L-2**: Extend `logoutAll()` with remember token invalidation
10. **BP-3**: Introduce LIKE escape helper
