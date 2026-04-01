<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Http\Requests\AdminResetUserMfaRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\MfaVerificationCodeRequest;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\PasswordResetRequestRequest;
use App\Http\Requests\TokenRequest;
use App\Http\Requests\TotpCodeRequest;
use App\Http\Requests\UpdateUserLanguageRequest;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\LoginMfaChallengeService;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Password reset token expiry time in minutes.
     */
    private const PASSWORD_RESET_TOKEN_EXPIRY_MINUTES = 60;

    /**
     * Activity log service for authentication events.
     */
    public function __construct(
        private ActivityLogService $activityLogService,
        private LoginMfaChallengeService $loginMfaChallengeService,
        private MfaService $mfaService,
    ) {}

    /**
     * SPA Login - Authenticate user and start session (for web SPA).
     *
     * Uses Laravel's session-based authentication with httpOnly cookies.
     * This is the preferred method for browser-based SPAs.
     *
     * For PWA (offline-first) apps, we always set remember=true to maintain
     * long-lived sessions. This allows users to stay logged in even after
     * the session expires, as Laravel will automatically restore the session
     * from the remember_token cookie.
     *
     * Security note: Users can explicitly log out via the canonical
     * /v1/auth/logout endpoint. The legacy /v1/auth/session/logout alias
     * also remains available for backward compatibility.
     *
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();
        $user = $this->validatePrimaryCredentials(
            $credentials['email'],
            $credentials['password'],
            $request->integer('tenant_id') ?: null,
        );

        if ($user->hasTwoFactorEnabled()) {
            return $this->issueLoginChallenge($user, LoginMfaChallengeService::LOGIN_CONTEXT_SESSION);
        }

        return $this->completeSessionLogin($request, $user);
    }

    /**
     * Legacy SPA logout alias.
     *
     * This preserves backward compatibility for existing SPA clients while
     * delegating to the same session logout logic as /v1/auth/logout.
     *
     * Explicitly resolves the user via the web guard so Bearer-token clients
     * receive a 401 instead of inadvertently clearing the remember-me state.
     */
    public function logoutSession(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if ($user === null) {
            return response()->json(['message' => __('Unauthenticated.')], 401);
        }

        return $this->logoutCurrentSession($request, $user);
    }

    /**
     * Generate a new API token for the user.
     *
     * This is for mobile/native apps that need Bearer token authentication.
     * For web SPAs, use the /auth/login endpoint instead.
     *
     * @throws ValidationException
     */
    public function token(TokenRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string, device_name?: string} $validated */
        $validated = $request->validated();
        $user = $this->validatePrimaryCredentials(
            $validated['email'],
            $validated['password'],
            $request->integer('tenant_id') ?: null,
        );

        $deviceName = $validated['device_name'] ?? 'api-client';

        if ($user->hasTwoFactorEnabled()) {
            return $this->issueLoginChallenge($user, LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN, $deviceName);
        }

        return $this->completeTokenLogin($user, $deviceName);
    }

    /**
     * Complete a pending MFA login challenge.
     *
     * @throws ValidationException
     */
    public function verifyMfaChallenge(MfaVerificationCodeRequest $request, string $challengeId): JsonResponse
    {
        if (! Str::isUuid($challengeId)) {
            return $this->resourceNotFoundResponse();
        }

        $challenge = $this->loginMfaChallengeService->find($challengeId);

        if ($challenge === null) {
            return $this->resourceNotFoundResponse();
        }

        /** @var User|null $user */
        $user = User::find($challenge['user_id']);

        if ($user === null) {
            $this->loginMfaChallengeService->forget($challengeId);

            return $this->resourceNotFoundResponse();
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->loginMfaChallengeService->forget($challengeId);

            return response()->json([
                'message' => __('Two-factor authentication is no longer enabled for this account.'),
            ], 409);
        }

        /** @var array{method: string, code: string} $validated */
        $validated = $request->validated();

        if (! $this->mfaService->verifyEnabledTwoFactorCode($user, $validated['method'], $validated['code'])) {
            $this->activityLogService->logLoginFailed($user->email, 'invalid_mfa_code', $user->tenant_id);

            throw ValidationException::withMessages([
                'code' => ['The provided multi-factor authentication code is invalid.'],
            ]);
        }

        if ($challenge['login_context'] === LoginMfaChallengeService::LOGIN_CONTEXT_SESSION) {
            if ($request->attributes->get('sanctum') !== true || ! $request->hasSession()) {
                return response()->json([
                    'message' => __('This MFA challenge must be completed from a browser session context.'),
                ], 409);
            }

            $this->loginMfaChallengeService->forget($challengeId);

            return $this->completeSessionLogin($request, $user, mfaCompleted: true);
        }

        $this->loginMfaChallengeService->forget($challengeId);

        return $this->completeTokenLogin($user, $challenge['device_name'] ?? 'api-client', mfaCompleted: true, createdStatus: 200);
    }

    /**
     * Log out the authenticated caller from the auth mode Sanctum resolved.
     *
     * Token-authenticated requests revoke only the current personal access token.
     * Stateful SPA requests invalidate the browser session and clear remember-me state.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // Log logout before revoking token
            $this->activityLogService->logLogout($user);

            // Idempotent token revocation; safe even if a concurrent logout already removed it
            $token->delete();

            return response()->json([
                'message' => __('Logged out successfully'),
            ]);
        }

        return $this->logoutCurrentSession($request, $user);
    }

    /**
     * Revoke all tokens for the authenticated user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->tokens()->delete();

        return response()->json([
            'message' => __('All tokens revoked successfully'),
        ]);
    }

    /**
     * Get the authenticated user's information.
     *
     * Returns user profile along with authorization context:
     * - roles: List of assigned role names
     * - permissions: List of all permission names (from roles + direct assignments)
     * - hasOrganizationalScopes: Whether user has any organizational scope assignments
     * - hasCustomerAccess: Whether user can access the customer collection globally or via scoped access
     * - hasSiteAccess: Whether user can access the site collection globally or via scoped access
     *
     * The hasOrganizationalScopes flag is used by the frontend to determine
     * whether to show organization/customer management navigation items.
     *
     * Note: Admin users have maximum organizational scopes (0-255) granting
     * access to all leadership levels and non-leadership employees.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->buildUserAuthorizationData($user));
    }

    /**
     * Update the authenticated user's language preference.
     */
    public function updateLanguage(UpdateUserLanguageRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{locale: string|null} $validated */
        $validated = $request->validated();

        $user->update([
            'preferred_locale' => $validated['locale'],
        ]);

        return response()->json([
            'data' => [
                'preferred_locale' => $user->preferred_locale,
            ],
        ]);
    }

    /**
     * Get the authenticated user's MFA status.
     */
    public function mfaStatus(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->mfaService->buildStatusData($user),
        ]);
    }

    /**
     * Start a new pending TOTP enrollment for the authenticated user.
     */
    public function startTotpEnrollment(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => __('Two-factor authentication is already enabled for this account.'),
            ], 409);
        }

        return response()->json([
            'data' => $this->mfaService->prepareEnrollment($user),
        ], 201);
    }

    /**
     * Confirm the authenticated user's pending TOTP enrollment.
     *
     * @throws ValidationException
     */
    public function confirmTotpEnrollment(TotpCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasPendingTwoFactorEnrollment()) {
            return response()->json([
                'message' => __('No pending two-factor enrollment exists for this account.'),
            ], 409);
        }

        if ($this->mfaService->pendingEnrollmentHasExpired($user)) {
            return response()->json([
                'message' => __('The pending two-factor enrollment has expired. Please start a new enrollment.'),
            ], 409);
        }

        /** @var array{code: string} $validated */
        $validated = $request->validated();

        if (! $this->mfaService->confirmPendingEnrollment($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The provided multi-factor authentication code is invalid.'],
            ]);
        }

        $user->refresh();

        $this->activityLogService->logUserMfaEvent(
            $user,
            'mfa_enabled',
            'Enabled multi-factor authentication',
            [
                'method' => 'totp',
                'recovery_codes_remaining' => $user->getRemainingTwoFactorRecoveryCodesCount(),
            ]
        );

        return response()->json([
            'data' => [
                'status' => $this->mfaService->buildStatusData($user),
                'recovery_codes' => [
                    'codes' => $this->mfaService->revealRecoveryCodes($user),
                    'generated_at' => $user->getTwoFactorRecoveryCodesGeneratedAt()?->format(DATE_ATOM),
                ],
            ],
        ]);
    }

    /**
     * Replace the authenticated user's recovery codes after verifying MFA.
     *
     * @throws ValidationException
     */
    public function regenerateRecoveryCodes(MfaVerificationCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => __('Two-factor authentication is not enabled for this account.'),
            ], 409);
        }

        /** @var array{method: string, code: string} $validated */
        $validated = $request->validated();

        if (! $this->mfaService->verifyEnabledTwoFactorCode($user, $validated['method'], $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The provided multi-factor authentication code is invalid.'],
            ]);
        }

        $recoveryCodes = $this->mfaService->regenerateRecoveryCodes($user);
        $user->refresh();

        $this->activityLogService->logUserMfaEvent(
            $user,
            'mfa_recovery_codes_regenerated',
            'Regenerated multi-factor recovery codes',
            [
                'verification_method' => $validated['method'],
                'recovery_codes_remaining' => $user->getRemainingTwoFactorRecoveryCodesCount(),
            ]
        );

        return response()->json([
            'data' => [
                'status' => $this->mfaService->buildStatusData($user),
                'recovery_codes' => [
                    'codes' => $recoveryCodes,
                    'generated_at' => $user->getTwoFactorRecoveryCodesGeneratedAt()?->format(DATE_ATOM),
                ],
            ],
        ]);
    }

    /**
     * Disable the authenticated user's MFA enrollment after verifying MFA.
     *
     * @throws ValidationException
     */
    public function disableMfa(MfaVerificationCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => __('Two-factor authentication is not enabled for this account.'),
            ], 409);
        }

        /** @var array{method: string, code: string} $validated */
        $validated = $request->validated();

        if (! $this->mfaService->verifyEnabledTwoFactorCode($user, $validated['method'], $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The provided multi-factor authentication code is invalid.'],
            ]);
        }

        $previousStatus = $this->mfaService->buildStatusData($user);

        $user->disableTwoFactorAuth();
        $user->refresh();

        $this->activityLogService->logUserMfaEvent(
            $user,
            'mfa_disabled',
            'Disabled multi-factor authentication',
            [
                'verification_method' => $validated['method'],
                'previous_status' => $previousStatus,
            ]
        );

        return response()->json([
            'data' => $this->mfaService->buildStatusData($user),
        ]);
    }

    /**
     * Allow privileged administrators to reset another user's MFA enrollment.
     */
    public function adminResetMfa(AdminResetUserMfaRequest $request, User $user): JsonResponse
    {
        Gate::authorize('resetMfa', $user);

        /** @var User $admin */
        $admin = $request->user();

        $user->loadMissing('twoFactorAuth');

        $previousStatus = $this->mfaService->buildStatusData($user);
        $hadPendingEnrollment = $user->hasPendingTwoFactorEnrollment();

        if (! $previousStatus['enabled'] && ! $hadPendingEnrollment) {
            return response()->json([
                'message' => __('Two-factor authentication is not configured for this account.'),
            ], 409);
        }

        /** @var array{reason: string} $validated */
        $validated = $request->validated();

        $user->disableTwoFactorAuth();
        $user->refresh();

        $this->activityLogService->logAdminMfaReset(
            $admin,
            $user,
            $validated['reason'],
            [
                'previous_status' => $previousStatus,
                'had_pending_enrollment' => $hadPendingEnrollment,
            ]
        );

        return response()->json([
            'message' => __('Two-factor authentication has been reset for the user.'),
            'data' => $this->mfaService->buildStatusData($user),
        ]);
    }

    /**
     * Request a password reset email.
     *
     * Security: Always returns 200 to prevent email enumeration.
     */
    public function passwordResetRequest(PasswordResetRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if ($user) {
            // Delete any existing tokens for this email
            DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->delete();

            // Generate secure token
            $token = Str::random(64);

            // Store hashed token
            DB::table('password_reset_tokens')->insert([
                'email' => $user->email,
                'token' => Hash::make($token),
                'created_at' => now(),
            ]);

            // Send password reset email (queued for async processing)
            Mail::to($user)->queue(new PasswordResetMail($user, $token));
        }

        // Always return same response to prevent email enumeration
        return response()->json([
            'message' => __('Password reset email sent if account exists'),
        ]);
    }

    /**
     * Reset password using token.
     */
    public function passwordReset(PasswordResetRequest $request): JsonResponse
    {
        /** @var array{token: string, email: string, password: string} $validated */
        $validated = $request->validated();

        // Find user
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'message' => __('Invalid or expired reset token'),
            ], 400);
        }

        // Get stored token record
        /** @var object{email: string, token: string, created_at: string}|null $tokenRecord */
        $tokenRecord = DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

        if (! $tokenRecord) {
            return response()->json([
                'message' => __('Invalid or expired reset token'),
            ], 400);
        }

        // Check if token is expired
        $createdAt = \Carbon\Carbon::parse($tokenRecord->created_at);
        $minutesAgo = $createdAt->diffInMinutes(now());

        if ($minutesAgo > self::PASSWORD_RESET_TOKEN_EXPIRY_MINUTES) {
            DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->delete();

            return response()->json([
                'message' => __('Invalid or expired reset token'),
            ], 400);
        }

        // Verify token
        if (! Hash::check($validated['token'], $tokenRecord->token)) {
            return response()->json([
                'message' => __('Invalid or expired reset token'),
            ], 400);
        }

        // Update password
        $user->password = Hash::make($validated['password']);
        $user->save();

        // Delete used token (one-time use)
        DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->delete();

        return response()->json([
            'message' => __('Password has been reset successfully'),
        ]);
    }

    /**
     * Build user data array with authorization context.
     *
     * Returns user profile along with authorization context:
     * - roles: List of assigned role names
     * - permissions: List of all permission names (from roles + direct assignments)
     * - hasOrganizationalScopes: Whether user has any organizational scope assignments
     * - hasCustomerAccess: Whether user can access the customer collection globally or via scoped access
     * - hasSiteAccess: Whether user can access the site collection globally or via scoped access
     *
     * The hasOrganizationalScopes flag is used by the frontend to determine
     * whether to show organization/customer management navigation items.
     *
     * Note: Admin users have maximum organizational scopes (0-255) granting
     * access to all leadership levels and non-leadership employees.
     *
     * @return array{id: string, name: string, email: string, roles: list<string>, permissions: list<string>, hasOrganizationalScopes: bool, hasCustomerAccess: bool, hasSiteAccess: bool}
     */
    private function buildUserAuthorizationData(User $user): array
    {
        // Eager load relationships to reduce database queries
        $user->load(['roles', 'permissions', 'organizationalScopes']);

        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->toArray();

        /** @var list<string> $permissions */
        $permissions = $user->getAllPermissions()->pluck('name')->toArray();

        $hasCustomerAccess = $user->can('customers.read') || $user->hasAccessibleCustomers();
        $hasSiteAccess = $user->can('sites.read') || $user->hasAccessibleSites();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles,
            'permissions' => $permissions,
            'hasOrganizationalScopes' => $user->organizationalScopes->isNotEmpty(),
            'hasCustomerAccess' => $hasCustomerAccess,
            'hasSiteAccess' => $hasSiteAccess,
        ];
    }

    /**
     * Validate primary email/password credentials for either login flow.
     *
     * @throws ValidationException
     */
    private function validatePrimaryCredentials(string $email, string $password, ?int $tenantId = null): User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->activityLogService->logLoginFailed($email, 'invalid_credentials', $tenantId);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $user;
    }

    /**
     * Return a pending MFA challenge instead of immediately completing login.
     */
    private function issueLoginChallenge(User $user, string $loginContext, ?string $deviceName = null): JsonResponse
    {
        return response()->json([
            'challenge' => $this->loginMfaChallengeService->create($user, $loginContext, $deviceName),
        ], 202);
    }

    /**
     * Complete a stateful browser session login.
     */
    private function completeSessionLogin(Request $request, User $user, bool $mfaCompleted = false): JsonResponse
    {
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        $this->activityLogService->logLoginSuccess($user);

        if ($mfaCompleted) {
            return response()->json($this->buildCompletedLoginResponse($user, LoginMfaChallengeService::LOGIN_CONTEXT_SESSION));
        }

        return response()->json([
            'user' => $this->buildUserAuthorizationData($user),
        ]);
    }

    /**
     * Complete a token login.
     */
    private function completeTokenLogin(User $user, string $deviceName, bool $mfaCompleted = false, int $createdStatus = 201): JsonResponse
    {
        $token = $user->createToken($deviceName);

        $this->activityLogService->logLoginSuccess($user);

        if ($mfaCompleted) {
            return response()->json(
                $this->buildCompletedLoginResponse($user, LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN, $token->plainTextToken),
                $createdStatus,
            );
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->buildUserAuthorizationData($user),
        ], $createdStatus);
    }

    /**
     * Build the final successful login payload returned after MFA challenge verification.
     *
     * @return array{user: array{id: string, name: string, email: string, roles: list<string>, permissions: list<string>, hasOrganizationalScopes: bool, hasCustomerAccess: bool, hasSiteAccess: bool}, authentication: array{mode: string, mfa_completed: bool}, token?: string}
     */
    private function buildCompletedLoginResponse(User $user, string $mode, ?string $token = null): array
    {
        $response = [
            'user' => $this->buildUserAuthorizationData($user),
            'authentication' => [
                'mode' => $mode,
                'mfa_completed' => true,
            ],
        ];

        if ($token !== null) {
            $response['token'] = $token;
        }

        return $response;
    }

    private function resourceNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'message' => __('Resource not found.'),
        ], 404);
    }

    /**
     * Invalidate the authenticated browser session and clear remember-me state.
     */
    private function logoutCurrentSession(Request $request, User $user): JsonResponse
    {
        $this->activityLogService->logLogout($user);

        $user->forceFill(['remember_token' => null])->save();

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => __('Logged out successfully'),
        ]);
    }
}
