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
use App\Services\RuntimeHeartbeatService;
use App\Services\SystemProcessExecutor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
            ->symbols()
            ->uncompromised());

        // Define rate limiters (using cache, not Redis)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Password reset rate limiter (5 per 60 minutes by IP)
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinutes(60, 5)->by($request->ip());
        });

        // Login rate limiter (5 attempts per minute for the account and the concrete IP+account pair).
        // This keeps the lockout independent from cookies / session churn while still partitioning per account.
        RateLimiter::for('login', function (Request $request) {
            return array_map(
                fn (string $key): Limit => $this->buildLoginThrottleLimit($key),
                $this->loginThrottleKeys($request),
            );
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
            return Limit::perMinutes(10, 5)
                ->by($this->mfaChallengeThrottleKey($request))
                ->after(fn (SymfonyResponse $response): bool => $this->shouldCountMfaChallengeAttempt($response))
                ->response(function (Request $request, array $headers): JsonResponse {
                    /** @var array<string, mixed> $headers */
                    $headers = $headers;

                    return $this->buildRateLimitedJsonResponse(
                        $headers,
                        'Too many MFA attempts. Please try again later.',
                    );
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
        $this->registerRuntimeHeartbeatHooks();
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

    private function registerRuntimeHeartbeatHooks(): void
    {
        $runtimeHeartbeatService = $this->app->make(RuntimeHeartbeatService::class);

        Event::listen(ScheduledTaskStarting::class, function () use ($runtimeHeartbeatService) {
            $runtimeHeartbeatService->recordSchedulerHeartbeat();
        });

        Queue::after(function (JobProcessed $event) use ($runtimeHeartbeatService) {
            $runtimeHeartbeatService->recordQueueHeartbeat($event->job->getQueue());
        });

        Queue::failing(function (JobFailed $event) use ($runtimeHeartbeatService) {
            $runtimeHeartbeatService->recordQueueHeartbeat($event->job->getQueue());
        });
    }

    private function onboardingThrottleKey(Request $request, string $scope): string
    {
        $emailInput = $request->input('email');
        $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';

        $rawKey = $email === ''
            ? $scope.'|'.$request->ip()
            : $scope.'|'.$request->ip().'|'.$email;

        return 'onboarding|'.hash('sha256', $rawKey);
    }

    /**
     * @return array<int, string>
     */
    private function loginThrottleKeys(Request $request): array
    {
        $email = $this->normalizedLoginThrottleEmail($request);

        if ($email === '') {
            return ['login|ip|'.$request->ip()];
        }

        return [
            'login|account|'.$email,
            'login|credential|'.$request->ip().'|'.$email,
        ];
    }

    private function normalizedLoginThrottleEmail(Request $request): string
    {
        $emailInput = $request->input('email', '');

        return is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    }

    private function buildLoginThrottleLimit(string $key): Limit
    {
        return Limit::perMinutes(5, 5)
            ->by($key)
            ->after(fn (SymfonyResponse $response): bool => $this->shouldCountLoginAttempt($response))
            ->response(function (Request $request, array $headers): JsonResponse {
                /** @var array<string, mixed> $headers */
                $headers = $headers;

                return $this->buildRateLimitedJsonResponse(
                    $headers,
                    'Too many login attempts. Please try again in :seconds seconds.',
                    ['seconds' => $this->retryAfterSeconds($headers)],
                );
            });
    }

    private function mfaChallengeThrottleKey(Request $request): string
    {
        $challengeId = (string) ($request->route('challengeId') ?? 'unknown');

        return $request->ip().'|'.$challengeId;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, bool|float|int|string|null>  $replace
     */
    private function buildRateLimitedJsonResponse(array $headers, string $message, array $replace = []): JsonResponse
    {
        return response()->json([
            'message' => __($message, $replace),
        ], 429, $headers);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function retryAfterSeconds(array $headers): int
    {
        $retryAfter = $headers['Retry-After'] ?? null;

        if (is_int($retryAfter)) {
            return $retryAfter;
        }

        if (is_string($retryAfter) && is_numeric($retryAfter)) {
            return (int) $retryAfter;
        }

        return 60;
    }

    private function shouldCountLoginAttempt(SymfonyResponse $response): bool
    {
        // Any 422 with an `email` field error on the login endpoint is an
        // invalid-credential failure; MFA challenges return 202, successes 200.
        return $this->responseHasValidationErrorForField($response, 'email');
    }

    private function shouldCountMfaChallengeAttempt(SymfonyResponse $response): bool
    {
        // Any 422 with a `code` field error on the MFA challenge endpoint is an
        // invalid-code failure; successful verifications return 200.
        return $this->responseHasValidationErrorForField($response, 'code');
    }

    private function responseHasValidationErrorForField(SymfonyResponse $response, string $field): bool
    {
        if ($response->getStatusCode() !== 422 || ! $response instanceof JsonResponse) {
            return false;
        }

        $data = $response->getData(true);

        if (! is_array($data)) {
            return false;
        }

        $errors = $data['errors'] ?? null;
        if (! is_array($errors)) {
            return false;
        }

        $fieldErrors = $errors[$field] ?? null;

        return is_array($fieldErrors) && $fieldErrors !== [];
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
