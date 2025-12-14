<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Providers;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeQualification;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Qualification;
use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\SecretShare;
use App\Observers\EmployeeObserver;
use App\Observers\PersonObserver;
use App\Observers\SecretObserver;
use App\Policies\EmployeeDocumentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmployeeQualificationPolicy;
use App\Policies\OnboardingFormSubmissionPolicy;
use App\Policies\OnboardingFormTemplatePolicy;
use App\Policies\OrganizationalUnitPolicy;
use App\Policies\PermissionManagementPolicy;
use App\Policies\QualificationPolicy;
use App\Policies\RoleManagementPolicy;
use App\Policies\SecretAttachmentPolicy;
use App\Policies\SecretPolicy;
use App\Policies\SecretSharePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Person::observe(PersonObserver::class);
        Secret::observe(SecretObserver::class);
        Employee::observe(EmployeeObserver::class);

        // Define rate limiters (using cache, not Redis)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Password reset rate limiter (5 per 60 minutes by IP)
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinutes(60, 5)->by($request->ip());
        });

        // Login rate limiter (5 attempts per minute by IP + email combination)
        // This applies to both session-based (/auth/login) and token-based (/auth/token) login
        RateLimiter::for('login', function (Request $request) {
            // Rate limit by IP + email to prevent enumeration attacks while
            // allowing multiple users from same IP (e.g., office network)
            $emailInput = $request->input('email', '');
            $email = is_string($emailInput) ? strtolower($emailInput) : '';
            $key = $request->ip().'|'.$email;

            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'message' => __('Too many login attempts. Please try again in :seconds seconds.', ['seconds' => 60]),
                ], 429);
            });
        });

        // Register policy for Spatie Role model
        Gate::policy(Role::class, RoleManagementPolicy::class);

        // Register policy for Spatie Permission model
        Gate::policy(Permission::class, PermissionManagementPolicy::class);

        // Register policy for Secret model
        Gate::policy(Secret::class, SecretPolicy::class);

        // Register policy for SecretShare model
        Gate::policy(SecretShare::class, SecretSharePolicy::class);

        // Register policy for SecretAttachment model
        Gate::policy(SecretAttachment::class, SecretAttachmentPolicy::class);

        // Register policies for Organizational Structure (Issue #236)
        Gate::policy(OrganizationalUnit::class, OrganizationalUnitPolicy::class);

        // Register policies for Employee Management (Issue #322 - Phase 4)
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(EmployeeDocument::class, EmployeeDocumentPolicy::class);
        Gate::policy(Qualification::class, QualificationPolicy::class);
        Gate::policy(EmployeeQualification::class, EmployeeQualificationPolicy::class);
        Gate::policy(OnboardingFormTemplate::class, OnboardingFormTemplatePolicy::class);
        Gate::policy(OnboardingFormSubmission::class, OnboardingFormSubmissionPolicy::class);

        // Register gates for user permission management
        $this->registerUserPermissionGates();
    }

    /**
     * Register authorization gates for direct user permission management.
     */
    private function registerUserPermissionGates(): void
    {
        $policy = new \App\Policies\UserPermissionPolicy;

        Gate::define('viewPermissions', function ($currentUser, $targetUser) use ($policy) {
            assert($currentUser instanceof \App\Models\User);
            assert($targetUser instanceof \App\Models\User);

            return $policy->viewPermissions($currentUser, $targetUser);
        });

        Gate::define('assignPermission', function ($currentUser, $targetUser) use ($policy) {
            assert($currentUser instanceof \App\Models\User);
            assert($targetUser instanceof \App\Models\User);

            return $policy->assignPermission($currentUser, $targetUser);
        });

        Gate::define('revokePermission', function ($currentUser, $targetUser) use ($policy) {
            assert($currentUser instanceof \App\Models\User);
            assert($targetUser instanceof \App\Models\User);

            return $policy->revokePermission($currentUser, $targetUser);
        });
    }
}
