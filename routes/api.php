<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AndroidEnrollmentSessionController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\CustomerAssignmentController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeQualificationController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OrganizationalScopeController;
use App\Http\Controllers\Api\V1\OrganizationalUnitController;
use App\Http\Controllers\Api\V1\PushDeviceRegistrationController;
use App\Http\Controllers\Api\V1\QualificationController;
use App\Http\Controllers\Api\V1\RoleManagementController;
use App\Http\Controllers\Api\V1\SiteAssignmentController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Http\Controllers\Api\V1\UserAssignmentController;
use App\Http\Controllers\Api\V1\UserPermissionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\RoleController;
use App\Http\Middleware\EnsureBrowserSessionLoginContext;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('throttle:health')->group(function () {
    Route::get('/health/live', [HealthController::class, 'live']);
    Route::get('/health/ready', [HealthController::class, 'ready']);

    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'service' => 'SecPal API',
        ]);
    });
});

// API v1 routes
Route::prefix('v1')->group(function () {
    Route::get('/bootstrap', [BootstrapController::class, 'show'])
        ->middleware('throttle:bootstrap');
    Route::get('/source', [SourceController::class, 'show'])
        ->middleware('throttle:source-offer');

    // Authentication routes (public)
    // SPA Login (session-based, for web browsers)
    // EnsureFrontendRequestsAreStateful middleware handles session/cookie middleware automatically
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware(['throttle:login', EnsureBrowserSessionLoginContext::class]);
    // Token Login (for mobile/native apps)
    Route::post('/auth/token', [AuthController::class, 'token'])
        ->middleware('throttle:login');
    Route::post('/auth/token/passkeys/challenges', [AuthController::class, 'startTokenPasskeyAuthenticationChallenge'])
        ->middleware('throttle:passkey-challenge');
    Route::post('/auth/token/passkeys/challenges/{challengeId}/verify', [AuthController::class, 'verifyTokenPasskeyAuthenticationChallenge'])
        ->middleware('throttle:passkey-verify');
    Route::post('/auth/passkeys/challenges', [AuthController::class, 'startPasskeyAuthenticationChallenge'])
        ->middleware('throttle:passkey-challenge');
    Route::post('/auth/passkeys/challenges/{challengeId}/verify', [AuthController::class, 'verifyPasskeyAuthenticationChallenge'])
        ->middleware('throttle:passkey-verify');
    Route::post('/auth/mfa-challenges/{challengeId}/verify', [AuthController::class, 'verifyMfaChallenge'])
        ->middleware('throttle:mfa-challenge');
    Route::post('/auth/password/reset-request', [AuthController::class, 'passwordResetRequest'])
        ->middleware('throttle:password-reset');
    Route::post('/auth/password/reset', [AuthController::class, 'passwordReset'])
        ->middleware('throttle:password-reset');
    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->whereUuid('id')
        ->name('verification.verify');

    // Onboarding completion (public, token-authenticated)
    Route::get('/onboarding/validate-token', [OnboardingController::class, 'validateToken'])
        ->middleware('throttle:onboarding-validate');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])
        ->middleware(['throttle:onboarding-complete', 'web']);
    Route::post('/android/bootstrap/exchange', [AndroidEnrollmentSessionController::class, 'exchange'])
        ->middleware('throttle:5,1');

    // Protected routes (require auth:sanctum)
    Route::middleware(['auth:sanctum', 'ability:'.User::API_ACCESS_ABILITY])->group(function () {
        // Token logout (for Bearer token auth - mobile/native apps)
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        // Session logout (for SPA cookie auth)
        Route::post('/auth/session/logout', [AuthController::class, 'logoutSession']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/auth/email/verification-notification', [AuthController::class, 'sendVerificationNotification'])
            ->middleware('throttle:6,1');
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/me/notification-installations/{installationId}', [PushDeviceRegistrationController::class, 'upsert'])
            ->whereUuid('installationId');
        Route::delete('/me/notification-installations/{installationId}', [PushDeviceRegistrationController::class, 'destroy'])
            ->whereUuid('installationId');

        Route::middleware('throttle:address-autocomplete')->group(function () {
            Route::get('/addresses/de/streets', [AddressController::class, 'streets']);
            Route::get('/addresses/de/localities', [AddressController::class, 'localities']);
            Route::get('/addresses/de/status', [AddressController::class, 'status']);
        });

        Route::middleware('verified')->group(function () {
            Route::get('/android-enrollment-sessions', [AndroidEnrollmentSessionController::class, 'index'])
                ->middleware('permission:android_enrollment.read');
            Route::post('/android-enrollment-sessions', [AndroidEnrollmentSessionController::class, 'store'])
                ->middleware('permission:android_enrollment.write');
            Route::get('/android-enrollment-sessions/{session}', [AndroidEnrollmentSessionController::class, 'show'])
                ->middleware('permission:android_enrollment.read');
            Route::post('/android-enrollment-sessions/{session}/revoke', [AndroidEnrollmentSessionController::class, 'revoke'])
                ->middleware('permission:android_enrollment.write');

            Route::patch('/me/language', [AuthController::class, 'updateLanguage']);
            Route::get('/me/mfa', [AuthController::class, 'mfaStatus']);
            Route::get('/me/passkeys', [AuthController::class, 'listPasskeys']);
            Route::post('/me/passkeys/challenges/registration', [AuthController::class, 'startPasskeyRegistrationChallenge'])
                ->middleware('throttle:passkey-challenge');
            Route::post('/me/passkeys/challenges/registration/{challengeId}/verify', [AuthController::class, 'verifyPasskeyRegistrationChallenge'])
                ->middleware('throttle:passkey-verify');
            Route::delete('/me/passkeys/{credentialId}', [AuthController::class, 'deletePasskey']);
            Route::delete('/me/mfa', [AuthController::class, 'disableMfa'])
                ->middleware('throttle:mfa');
            Route::post('/me/mfa/totp/enrollment', [AuthController::class, 'startTotpEnrollment'])
                ->middleware('throttle:mfa');
            Route::post('/me/mfa/totp/enrollment/confirm', [AuthController::class, 'confirmTotpEnrollment'])
                ->middleware('throttle:mfa');
            Route::post('/me/mfa/recovery-codes/regenerate', [AuthController::class, 'regenerateRecoveryCodes'])
                ->middleware('throttle:mfa');

            // Role Management CRUD API
            // Authorization: Route-level permission middleware + Policy (defense-in-depth)
            Route::get('/roles', [RoleManagementController::class, 'index'])
                ->middleware('permission:roles.read');
            Route::post('/roles', [RoleManagementController::class, 'store'])
                ->middleware('permission:roles.create');
            Route::get('/roles/{id}', [RoleManagementController::class, 'show'])
                ->middleware('permission:roles.read');
            Route::patch('/roles/{id}', [RoleManagementController::class, 'update'])
                ->middleware('permission:roles.update');
            Route::delete('/roles/{id}', [RoleManagementController::class, 'destroy'])
                ->middleware('permission:roles.delete');

            // Role management endpoints
            Route::post('/users/{user}/roles', [RoleController::class, 'store'])
                ->middleware('permission:role.assign');
            Route::get('/users/{user}/roles', [RoleController::class, 'index'])
                ->middleware('permission:role.read');
            Route::delete('/users/{user}/roles/{role}', [RoleController::class, 'destroy'])
                ->middleware('permission:role.revoke');
            Route::patch('/users/{user}/roles/{role}/extend', [RoleController::class, 'extend'])
                ->middleware('permission:role.assign');

            // User Direct Permission Assignment API (RBAC Phase 4)
            // Authorization: Policy-based (users can view own, privileged users can view all/modify)
            Route::get('/users/{user}/permissions', [UserPermissionController::class, 'index']);
            // Authorization: Route-level permission middleware + Policy (dedicated permission required)
            Route::post('/users/{user}/permissions', [UserPermissionController::class, 'store'])
                ->middleware('permission:permissions.assign_direct');
            Route::delete('/users/{user}/permissions/{permission}', [UserPermissionController::class, 'destroy'])
                ->middleware('permission:permissions.revoke_direct');
            // Authorization: Policy-based (users can view own, privileged users can view all)
            Route::get('/users/{user}/permissions/direct', [UserPermissionController::class, 'direct']);
            Route::delete('/users/{user}/mfa', [AuthController::class, 'resetUserMfa'])
                ->middleware(['permission:users.reset_mfa', 'throttle:mfa-user-reset']);

            // Tenant-scoped Person endpoints
            Route::prefix('tenants/{tenant}')->middleware('tenant')->group(function () {
                Route::post('/persons', [PersonController::class, 'store'])
                    ->middleware('permission:person.write');
                Route::get('/persons/by-email', [PersonController::class, 'byEmail'])
                    ->middleware('permission:person.read');
            });

            // Organizational Unit Scope Management (RBAC Issue #234)
            // Defense-in-depth: Middleware pre-checks scope-management access, controller uses policy for authorization
            Route::get('/me/organizational-scopes', [OrganizationalScopeController::class, 'myScopes']);
            Route::prefix('organizational-units/{organizational_unit}')
                ->middleware('check.organizational.scope:manage')
                ->group(function () {
                    Route::get('/scopes', [OrganizationalScopeController::class, 'index']);
                    Route::post('/scopes', [OrganizationalScopeController::class, 'store']);
                    Route::patch('/scopes/{scope}', [OrganizationalScopeController::class, 'update']);
                    Route::delete('/scopes/{scope}', [OrganizationalScopeController::class, 'destroy']);
                });

            // ==========================================================================
            // Organizational Structure & Customer Hierarchies REST API (Issue #236)
            // All routes use tenant.inject middleware for automatic tenant_id injection
            // Authorization is handled by respective Policies (defense-in-depth)
            // ==========================================================================

            // Organizational Units (internal company structure)
            Route::middleware('tenant.inject')->group(function () {
                Route::get('/organizational-units', [OrganizationalUnitController::class, 'index']);
                Route::post('/organizational-units', [OrganizationalUnitController::class, 'store']);
                Route::get('/organizational-units/{organizational_unit}', [OrganizationalUnitController::class, 'show']);
                Route::patch('/organizational-units/{organizational_unit}', [OrganizationalUnitController::class, 'update']);
                Route::delete('/organizational-units/{organizational_unit}', [OrganizationalUnitController::class, 'destroy']);
                // Hierarchy management
                Route::get('/organizational-units/{organizational_unit}/descendants', [OrganizationalUnitController::class, 'descendants']);
                Route::get('/organizational-units/{organizational_unit}/ancestors', [OrganizationalUnitController::class, 'ancestors']);
                Route::post('/organizational-units/{organizational_unit}/parent', [OrganizationalUnitController::class, 'attachParent']);
                Route::delete('/organizational-units/{organizational_unit}/parent/{parent}', [OrganizationalUnitController::class, 'detachParent']);
            });

            // ==========================================================================
            // Customer & Site Management REST API (Issue #313 - Epic #210 Phase 1)
            // All routes use tenant.inject middleware for automatic tenant_id injection
            // Authorization is handled by respective Policies (defense-in-depth)
            // ==========================================================================

            // Customers (external organizations/companies)
            Route::middleware('tenant.inject')->group(function () {
                Route::get('/customers', [CustomerController::class, 'index']);
                Route::post('/customers', [CustomerController::class, 'store']);
                Route::get('/customers/{customer}', [CustomerController::class, 'show']);
                Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
                Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
                // Nested resource: customer's sites
                Route::get('/customers/{customer}/sites', [CustomerController::class, 'sites']);
            });

            // Sites (physical locations where services are provided)
            Route::middleware('tenant.inject')->scopeBindings()->group(function () {
                Route::get('/sites', [SiteController::class, 'index']);
                Route::post('/sites', [SiteController::class, 'store']);
                Route::get('/sites/{site}', [SiteController::class, 'show']);
                Route::patch('/sites/{site}', [SiteController::class, 'update']);
                Route::delete('/sites/{site}', [SiteController::class, 'destroy']);
                // Nested resource: site's cost centers
                Route::get('/sites/{site}/cost-centers', [CostCenterController::class, 'index']);
                Route::post('/sites/{site}/cost-centers', [CostCenterController::class, 'store']);
                Route::get('/sites/{site}/cost-centers/{costCenter}', [CostCenterController::class, 'show']);
                Route::put('/sites/{site}/cost-centers/{costCenter}', [CostCenterController::class, 'update']);
                Route::delete('/sites/{site}/cost-centers/{costCenter}', [CostCenterController::class, 'destroy']);
            });

            // ==========================================================================
            // Assignment Management REST API (Issue #315 - Epic #210 Phase 4.3)
            // Flexible user-to-customer/site role assignments with tenant-specific terminology
            // All routes use tenant.inject middleware for automatic tenant_id injection
            // Authorization is handled by respective Policies (defense-in-depth)
            // ==========================================================================

            // Customer Assignments (user → customer flexible role assignments)
            Route::middleware('tenant.inject')->group(function () {
                Route::get('/customers/{customer}/assignments', [CustomerAssignmentController::class, 'index']);
                Route::post('/customers/{customer}/assignments', [CustomerAssignmentController::class, 'store']);
                Route::patch('/customer-assignments/{customerAssignment}', [CustomerAssignmentController::class, 'update']);
                Route::delete('/customer-assignments/{customerAssignment}', [CustomerAssignmentController::class, 'destroy']);
            });

            // Site Assignments (user → site flexible role assignments)
            Route::middleware('tenant.inject')->group(function () {
                Route::get('/sites/{site}/assignments', [SiteAssignmentController::class, 'index']);
                Route::post('/sites/{site}/assignments', [SiteAssignmentController::class, 'store']);
                Route::patch('/site-assignments/{siteAssignment}', [SiteAssignmentController::class, 'update']);
                Route::delete('/site-assignments/{siteAssignment}', [SiteAssignmentController::class, 'destroy']);
            });

            // User Assignments ("My Assignments" - retrieve authenticated user's assignments)
            Route::middleware('tenant.inject')->group(function () {
                Route::get('/me/customer-assignments', [UserAssignmentController::class, 'customerAssignments']);
                Route::get('/me/site-assignments', [UserAssignmentController::class, 'siteAssignments']);
            });

            // ==========================================================================
            // Employee Management REST API (Issue #323 - Epic #211 Phase 5)
            // All routes use tenant.inject middleware for automatic tenant_id injection
            // Authorization is handled by respective Policies (defense-in-depth)
            // ==========================================================================

            // Employees (HR module)
            Route::middleware('tenant.inject')->group(function () {
                Route::get('/employees', [EmployeeController::class, 'index']);
                Route::get('/employees/compliance-alerts', [EmployeeController::class, 'complianceAlerts']);
                Route::post('/employees', [EmployeeController::class, 'store']);
                Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
                Route::patch('/employees/{employee}', [EmployeeController::class, 'update']);
                Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
                Route::post('/employees/{employee}/bwr/export', [EmployeeController::class, 'exportBwr']);
                Route::put('/employees/{employee}/bwr/status', [EmployeeController::class, 'updateBwrStatus']);
                Route::get('/employees/{employee}/bwr/exports/{file}/download', [EmployeeController::class, 'downloadBwrExport'])
                    ->name('employees.bwr-exports.download');
                // Status transitions
                Route::post('/employees/{employee}/activate', [EmployeeController::class, 'activate']);
                Route::post('/employees/{employee}/leave', [EmployeeController::class, 'placeOnLeave']);
                Route::post('/employees/{employee}/return-from-leave', [EmployeeController::class, 'returnFromLeave']);
                Route::post('/employees/{employee}/terminate', [EmployeeController::class, 'terminate']);
            });

            // Qualifications (system + custom)
            Route::middleware('tenant.inject')->group(function () {
                Route::get('/qualifications', [QualificationController::class, 'index']);
                Route::post('/qualifications', [QualificationController::class, 'store']);
                Route::get('/qualifications/{qualification}', [QualificationController::class, 'show']);
                Route::patch('/qualifications/{qualification}', [QualificationController::class, 'update']);
                Route::delete('/qualifications/{qualification}', [QualificationController::class, 'destroy']);
            });

            // Employee Qualifications (assignments)
            Route::middleware('tenant.inject')->group(function () {
                Route::get('/employees/{employee}/qualifications', [EmployeeQualificationController::class, 'index']);
                Route::post('/employees/{employee}/qualifications', [EmployeeQualificationController::class, 'store']);
                Route::get('/employee-qualifications/{employeeQualification}', [EmployeeQualificationController::class, 'show']);
                Route::patch('/employee-qualifications/{employeeQualification}', [EmployeeQualificationController::class, 'update']);
                Route::delete('/employee-qualifications/{employeeQualification}', [EmployeeQualificationController::class, 'destroy']);
            });

            // Employee Documents
            Route::middleware('tenant.inject')->scopeBindings()->group(function () {
                Route::get('/employees/{employee}/documents', [EmployeeDocumentController::class, 'index']);
                Route::post('/employees/{employee}/documents', [EmployeeDocumentController::class, 'store']);
                Route::get('/employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'show']);
                Route::get('/employees/{employee}/documents/{document}/download', [EmployeeDocumentController::class, 'download']);
                Route::delete('/employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy']);
            });
        });

        // Onboarding (pre-contract employees only)
        Route::middleware('tenant.inject')->group(function () {
            // Employee-facing endpoints
            Route::get('/onboarding/steps', [OnboardingController::class, 'getSteps']);
            Route::get('/onboarding/nationalities', [OnboardingController::class, 'getNationalities']);
            Route::get('/onboarding/templates', [OnboardingController::class, 'getTemplates']);
            Route::get('/onboarding/templates/{template}', [OnboardingController::class, 'getTemplate']);
            Route::get('/onboarding/submissions', [OnboardingController::class, 'getSubmissions']);
            Route::post('/onboarding/submissions', [OnboardingController::class, 'submitForm']);
            Route::patch('/onboarding/submissions/{submission}', [OnboardingController::class, 'updateSubmission']);
            Route::post('/onboarding/submissions/{submission}/files', [OnboardingController::class, 'uploadSubmissionFile']);
            Route::delete('/onboarding/submissions/{submission}/files/{file}', [OnboardingController::class, 'deleteSubmissionFile']);
            Route::get('/onboarding/completion-status', [OnboardingController::class, 'getCompletionStatus']);
        });

        Route::middleware(['verified', 'tenant.inject'])->group(function () {
            // HR approval endpoints
            Route::post('/onboarding-review/submissions/{submission}/approve', [OnboardingController::class, 'approveSubmission']);
            Route::post('/onboarding-review/submissions/{submission}/reject', [OnboardingController::class, 'rejectSubmission']);
            Route::post('/onboarding-review/employees/{employee}/confirm', [OnboardingController::class, 'confirmEmployeeOnboarding']);
        });

        // ==========================================================================
        // Activity Logs REST API (Issue #394 - Epic #385 Phase 6)
        // All routes use tenant.inject middleware for automatic tenant_id injection
        // Authorization handled by ActivityPolicy (defense-in-depth)
        // Features: Scoped filtering, leadership level filtering, verification
        // ==========================================================================
        Route::middleware(['verified', 'tenant.inject'])->group(function () {
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::get('/activity-logs/{activity}', [ActivityLogController::class, 'show']);
            Route::get('/activity-logs/{activity}/verify', [ActivityLogController::class, 'verify']);
        });
    });
});
