<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Providers;

use App\Contracts\ProcessExecutor;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeQualification;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Qualification;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Observers\EmployeeObserver;
use App\Observers\PersonObserver;
use App\Policies\CostCenterPolicy;
use App\Policies\CustomerAssignmentPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\EmployeeDocumentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmployeeQualificationPolicy;
use App\Policies\OnboardingFormSubmissionPolicy;
use App\Policies\OnboardingFormTemplatePolicy;
use App\Policies\OrganizationalUnitPolicy;
use App\Policies\PermissionManagementPolicy;
use App\Policies\QualificationPolicy;
use App\Policies\RoleManagementPolicy;
use App\Policies\SiteAssignmentPolicy;
use App\Policies\SitePolicy;
use App\Services\SystemProcessExecutor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind ProcessExecutor interface to SystemProcessExecutor
        $this->app->bind(ProcessExecutor::class, SystemProcessExecutor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Person::observe(PersonObserver::class);
        Employee::observe(EmployeeObserver::class);

        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->numbers()
            ->symbols());

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

        RateLimiter::for('mfa', function (Request $request) {
            $scope = $request->route()?->uri() ?? $request->path();
            $key = ($request->user()?->id ?: $request->ip()).'|'.$scope;

            return Limit::perMinutes(10, 5)->by($key)->response(function () {
                return response()->json([
                    'message' => __('Too many MFA attempts. Please try again later.'),
                ], 429);
            });
        });

        RateLimiter::for('mfa-challenge', function (Request $request) {
            $challengeId = (string) ($request->route('challengeId') ?? 'unknown');
            $key = $request->ip().'|'.$challengeId;

            return Limit::perMinutes(10, 5)->by($key)->response(function () {
                return response()->json([
                    'message' => __('Too many MFA attempts. Please try again later.'),
                ], 429);
            });
        });

        RateLimiter::for('mfa-admin-reset', function (Request $request) {
            $actor = $request->user();
            $actorId = $actor instanceof \App\Models\User
                ? $actor->id
                : (string) $request->ip();

            $targetUser = $request->route('user');
            if ($targetUser instanceof \App\Models\User) {
                $targetId = $targetUser->id;
            } elseif (is_string($targetUser) || is_int($targetUser)) {
                $targetId = (string) $targetUser;
            } else {
                $targetId = 'unknown';
            }

            $key = $actorId.'|'.$targetId;

            return Limit::perMinutes(10, 3)->by($key)->response(function () {
                return response()->json([
                    'message' => __('Too many MFA reset attempts. Please try again later.'),
                ], 429);
            });
        });

        // Onboarding link validation should stay usable for legitimate reloads,
        // so only business-level failures count toward the validate limiter.
        RateLimiter::for('onboarding-validate', function (Request $request) {
            return Limit::perMinutes(10, 3)
                ->by($this->onboardingThrottleKey($request, 'validate'))
                ->after(fn (SymfonyResponse $response): bool => $this->shouldCountOnboardingAttempt($response))
                ->response(function () {
                    return response()->json([
                        'message' => __('Too many onboarding attempts. Please try again later.'),
                    ], 429);
                });
        });

        // Onboarding completion keeps a separate bucket so validation refreshes
        // do not block the actual account setup step.
        RateLimiter::for('onboarding-complete', function (Request $request) {
            return Limit::perMinutes(10, 3)
                ->by($this->onboardingThrottleKey($request, 'complete'))
                ->after(fn (SymfonyResponse $response): bool => $this->shouldCountOnboardingAttempt($response))
                ->response(function () {
                    return response()->json([
                        'message' => __('Too many onboarding attempts. Please try again later.'),
                    ], 429);
                });
        });

        // Register policy for Spatie Role model
        Gate::policy(Role::class, RoleManagementPolicy::class);

        // Register policy for Spatie Permission model
        Gate::policy(Permission::class, PermissionManagementPolicy::class);

        // Register policies for Customer & Site Management (Epic #210)
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(CostCenter::class, CostCenterPolicy::class);
        Gate::policy(CustomerAssignment::class, CustomerAssignmentPolicy::class);
        Gate::policy(SiteAssignment::class, SiteAssignmentPolicy::class);

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
        $this->registerUserMfaGates();
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

    /**
     * Register authorization gates for cross-user MFA administration.
     */
    private function registerUserMfaGates(): void
    {
        $policy = new \App\Policies\UserMfaPolicy;

        Gate::define('resetMfa', function ($currentUser, $targetUser) use ($policy) {
            assert($currentUser instanceof \App\Models\User);
            assert($targetUser instanceof \App\Models\User);

            return $policy->resetMfa($currentUser, $targetUser);
        });
    }

    private function onboardingThrottleKey(Request $request, string $scope): string
    {
        return $scope.'|'.$request->ip();
    }

    private function shouldCountOnboardingAttempt(SymfonyResponse $response): bool
    {
        if ($response->getStatusCode() === 403) {
            return true;
        }

        if ($response->getStatusCode() !== 422) {
            return false;
        }

        if (! $response instanceof JsonResponse) {
            return true;
        }

        $data = $response->getData(true);

        return ! is_array($data) || ! array_key_exists('errors', $data);
    }
}
