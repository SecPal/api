<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\MfaVerificationCodeRequest;
use App\Http\Requests\PasskeyAuthenticationChallengeRequest;
use App\Http\Requests\PasskeyAuthenticationVerificationRequest;
use App\Http\Requests\PasskeyRegistrationVerificationRequest;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\PasswordResetRequestRequest;
use App\Http\Requests\TokenPasskeyAuthenticationChallengeRequest;
use App\Http\Requests\TokenRequest;
use App\Http\Requests\TotpCodeRequest;
use App\Http\Requests\UpdateUserLanguageRequest;
use App\Http\Requests\UserMfaResetRequest;
use App\Mail\PasswordResetMail;
use App\Models\Employee;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\LoginMfaChallengeService;
use App\Services\MfaService;
use App\Services\PasskeyChallengeService;
use App\Services\PasskeyService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;
use Webauthn\Exception\WebauthnException;

class AuthController extends Controller
{
    /**
     * Password reset token expiry time in minutes.
     */
    private const PASSWORD_RESET_TOKEN_EXPIRY_MINUTES = 60;

    /**
     * Cache prefix for the placeholder hash used on unknown-user logins.
     */
    private const DUMMY_PASSWORD_HASH_CACHE_PREFIX = 'auth:dummy-password-hash:v1';

    private const DUMMY_PASSWORD_PLACEHOLDER = 'secpal-timing-protection-placeholder';

    private const FALLBACK_DUMMY_PASSWORD_HASH = '$2y$12$fAJGA/LIzR7AAtIjg4UYxuj6V0hnGJxYaEB5pvNIjO9CJt6KPU8Hy';

    private const PASSKEY_AUTHENTICATION_PLACEHOLDER_USER_ID = '00000000-0000-0000-0000-000000000000';

    /**
     * Activity log service for authentication events.
     */
    public function __construct(
        private ActivityLogService $activityLogService,
        private LoginMfaChallengeService $loginMfaChallengeService,
        private MfaService $mfaService,
        private PasskeyChallengeService $passkeyChallengeService,
        private PasskeyService $passkeyService,
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
            $this->activityLogService->logLoginFailed($user->email, 'invalid_mfa_code');
            $this->loginMfaChallengeService->forget($challengeId);

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

        $usesSessionAuthentication = ! ($user->currentAccessToken() instanceof PersonalAccessToken);

        DB::transaction(function () use ($user) {
            $this->activityLogService->logLogoutAll($user);

            $user->tokens()->delete();

            DB::table('sessions')
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();

            $user->forceFill(['remember_token' => null])->save();
        });

        if ($usesSessionAuthentication) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            // Clear all resolved guard caches so that Auth::id() returns null when
            // the session middleware writes the new session row to the sessions table.
            // forgetGuards() clears AuthManager::$guards, but DatabaseSessionHandler
            // resolves the user via the 'auth.driver' IoC singleton (Guard::class alias),
            // which is cached independently of AuthManager::$guards. Without the
            // forgetInstance call the Sanctum RequestGuard singleton would still return
            // the authenticated user and the new session row would be written with the
            // departing user's ID.
            app('auth')->forgetGuards();
            app()->forgetInstance('auth.driver');
        }

        return response()->json([
            'message' => __('All tokens revoked successfully'),
        ]);
    }

    /**
     * Send a fresh email verification notification to the authenticated user.
     */
    public function sendVerificationNotification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => __('Email address is already verified.'),
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => __('Verification link sent successfully.'),
        ], 202);
    }

    /**
     * Verify an email address using a signed verification link.
     */
    public function verifyEmail(string $id, string $hash): JsonResponse
    {
        /** @var User|null $user */
        $user = User::find($id);

        if ($user === null || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return $this->resourceNotFoundResponse();
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => __('Email address is already verified.'),
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json([
            'message' => __('Email address verified successfully.'),
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
     * Note: users with full organizational scope coverage can hold maximum
     * viewable rank ranges (0-255), granting access to all leadership
     * levels and non-leadership employees.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->buildUserAuthorizationData($user));
    }

    /**
     * Start a browser passkey authentication challenge.
     */
    public function startPasskeyAuthenticationChallenge(PasskeyAuthenticationChallengeRequest $request): JsonResponse
    {
        if (($contextResponse = $this->requireBrowserSessionContext($request)) !== null) {
            return $contextResponse;
        }

        /** @var array{email?: string|null} $validated */
        $validated = $request->validated();

        return $this->createPasskeyAuthenticationChallengeResponse($validated['email'] ?? null);
    }

    /**
     * Start a token-based native passkey authentication challenge.
     */
    public function startTokenPasskeyAuthenticationChallenge(TokenPasskeyAuthenticationChallengeRequest $request): JsonResponse
    {
        /** @var array{email?: string|null, device_name: string} $validated */
        $validated = $request->validated();

        return $this->createPasskeyAuthenticationChallengeResponse(
            $validated['email'] ?? null,
            LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN,
            $validated['device_name'],
        );
    }

    /**
     * Verify a browser passkey authentication challenge and establish a session.
     */
    public function verifyPasskeyAuthenticationChallenge(
        PasskeyAuthenticationVerificationRequest $request,
        string $challengeId
    ): JsonResponse {
        if (($contextResponse = $this->requireBrowserSessionContext($request)) !== null) {
            return $contextResponse;
        }

        return $this->completePasskeyAuthenticationChallenge(
            $request,
            $challengeId,
            LoginMfaChallengeService::LOGIN_CONTEXT_SESSION,
        );
    }

    /**
     * Verify a token-based native passkey authentication challenge and issue a token.
     */
    public function verifyTokenPasskeyAuthenticationChallenge(
        PasskeyAuthenticationVerificationRequest $request,
        string $challengeId
    ): JsonResponse {
        return $this->completePasskeyAuthenticationChallenge(
            $request,
            $challengeId,
            LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN,
        );
    }

    /**
     * Return the authenticated user's enrolled passkeys.
     */
    public function listPasskeys(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('passkeyCredentials');

        return response()->json([
            'data' => $this->passkeyService->listCredentials($user),
        ]);
    }

    /**
     * Start a passkey registration challenge for the authenticated user.
     */
    public function startPasskeyRegistrationChallenge(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('passkeyCredentials');

        $challenge = $this->passkeyChallengeService->createRegistrationChallenge(
            $user,
            $this->passkeyService->buildRegistrationOptions($user),
        );

        return response()->json([
            'data' => [
                'challenge_id' => $challenge['challenge_id'],
                'public_key' => $this->passkeyService->formatApiPayload($challenge['public_key']),
                'expires_at' => $challenge['expires_at'],
            ],
        ], 201);
    }

    /**
     * Verify a passkey registration challenge for the authenticated user.
     */
    public function verifyPasskeyRegistrationChallenge(
        PasskeyRegistrationVerificationRequest $request,
        string $challengeId
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($challengeId)) {
            return $this->resourceNotFoundResponse();
        }

        $challenge = $this->passkeyChallengeService->findRegistrationChallenge($challengeId);

        if ($challenge === null || $challenge['user_id'] !== $user->id) {
            return $this->resourceNotFoundResponse();
        }

        /** @var array{credential: array<string, mixed>, label?: string|null} $validated */
        $validated = $request->validated();

        try {
            $credential = $this->passkeyService->verifyRegistration(
                $user,
                $challenge['public_key'],
                $validated['credential'],
                $validated['label'] ?? null,
            );
        } catch (WebauthnException $exception) {
            $this->passkeyChallengeService->forgetRegistrationChallenge($challengeId);

            throw $this->passkeyCredentialValidationException($exception);
        } catch (Throwable $exception) {
            $this->passkeyChallengeService->forgetRegistrationChallenge($challengeId);

            report($exception);

            Log::warning('Passkey registration verification failed with unexpected error', [
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'credential' => ['The passkey credential could not be verified.'],
            ]);
        }

        $this->passkeyChallengeService->forgetRegistrationChallenge($challengeId);

        $this->activityLogService->logUserMfaEvent(
            $user,
            'passkey_registered',
            'Registered a passkey credential',
            [
                'credential_id' => $credential->credential_id,
                'label' => $credential->label,
            ],
        );

        $freshCredential = $credential->fresh();

        return response()->json([
            'data' => [
                'credential' => $this->passkeyService->formatCredentialSummary(
                    $freshCredential instanceof PasskeyCredential ? $freshCredential : $credential,
                ),
                'total_passkeys' => $user->passkeyCredentials()->count(),
            ],
        ], 201);
    }

    /**
     * Delete one enrolled passkey from the authenticated user.
     */
    public function deletePasskey(Request $request, string $credentialId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $credential = $user->passkeyCredentials()
            ->where('credential_id', $credentialId)
            ->first();

        if (! $credential instanceof PasskeyCredential) {
            return $this->resourceNotFoundResponse();
        }

        $result = $this->passkeyService->deleteCredential($user, $credential);

        $this->activityLogService->logUserMfaEvent(
            $user,
            'passkey_deleted',
            'Deleted a passkey credential',
            [
                'credential_id' => $credentialId,
                'label' => $credential->label,
            ],
        );

        return response()->json([
            'message' => __('Passkey deleted successfully.'),
            'data' => $result,
        ]);
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
            $this->throwIfTotpCodeRecentlyUsed($user, $validated['method'], $validated['code']);

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
            $this->throwIfTotpCodeRecentlyUsed($user, $validated['method'], $validated['code']);

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
     * Allow a privileged operator to reset another user's MFA enrollment.
     */
    public function resetUserMfa(UserMfaResetRequest $request, User $user): JsonResponse
    {
        Gate::authorize('resetMfa', $user);

        /** @var User $actor */
        $actor = $request->user();

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

        $this->activityLogService->logPrivilegedMfaReset(
            $actor,
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
        $startedAt = hrtime(true);
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

        $this->enforcePasswordResetMinimumResponseTime($startedAt);

        // Always return same response to prevent email enumeration
        return response()->json([
            'message' => __('Password reset email sent if account exists'),
        ]);
    }

    private function enforcePasswordResetMinimumResponseTime(int $startedAt): void
    {
        $minimumDelaySetting = config('auth.password_reset_min_response_time_ms', 50);
        $minimumDelayMilliseconds = is_int($minimumDelaySetting)
            ? $minimumDelaySetting
            : (is_numeric($minimumDelaySetting) ? (int) $minimumDelaySetting : 50);
        $minimumDelayMilliseconds = max(0, $minimumDelayMilliseconds);
        $minimumDelayNanoseconds = $minimumDelayMilliseconds * 1_000_000;
        $elapsedNanoseconds = hrtime(true) - $startedAt;

        if ($elapsedNanoseconds >= $minimumDelayNanoseconds) {
            return;
        }

        usleep((int) ceil(($minimumDelayNanoseconds - $elapsedNanoseconds) / 1000));
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

        $invalidToken = false;

        DB::transaction(function () use ($user, $validated, &$invalidToken): void {
            // Re-fetch and lock the token row to prevent concurrent consumption (TOCTOU)
            /** @var object{email: string, token: string, created_at: string}|null $lockedToken */
            $lockedToken = DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            if (! $lockedToken || ! Hash::check($validated['token'], $lockedToken->token)) {
                $invalidToken = true;

                return;
            }

            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'remember_token' => null,
            ])->save();

            $user->tokens()->delete();

            DB::table('sessions')
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();

            $this->activityLogService->logPasswordReset($user);

            // Delete used token (one-time use)
            DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->delete();
        });

        if ($invalidToken) {
            return response()->json([
                'message' => __('Invalid or expired reset token'),
            ], 400);
        }

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
     * - emailVerified: Whether the user's email address has been verified
     *
     * The hasOrganizationalScopes flag is used by the frontend to determine
     * whether to show organization/customer management navigation items.
     *
     * Note: users with full organizational scope coverage can hold maximum
     * viewable rank ranges (0-255), granting access to all leadership
     * levels and non-leadership employees.
     *
     * @return array{id: string, name: string, email: string, emailVerified: bool, roles: list<string>, permissions: list<string>, hasOrganizationalScopes: bool, hasCustomerAccess: bool, hasSiteAccess: bool, employeeStatus: string|null, onboardingWorkflowStatus: string|null, employee: array{id: string, contractStartDate: string|null}|null}
     */
    private function buildUserAuthorizationData(User $user): array
    {
        $this->normalizeAuthenticatedEmployeeWorkflow($user);

        // Ensure the Spatie Permission team context is set before querying
        // roles and permissions.  Authentication routes (login, passkey verify,
        // MFA verify) execute before the global InjectTenantId middleware can
        // resolve an authenticated user, leaving the PermissionRegistrar with
        // a null team.  Eager-loading then builds the roles relation on a blank
        // model instance whose tenant_id is null, resulting in empty results.
        // Setting the team explicitly from the concrete user prevents this.
        if ($user->tenant_id !== null) {
            app(\Spatie\Permission\PermissionRegistrar::class)
                ->setPermissionsTeamId($user->tenant_id);
        }

        // Eager load relationships to reduce database queries
        $user->load(['roles', 'permissions', 'organizationalScopes', 'employee']);

        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->toArray();

        /** @var list<string> $permissions */
        $permissions = $user->getAllPermissions()->pluck('name')->toArray();

        $hasCustomerAccess = $user->can('customers.read') || $user->hasAccessibleCustomers();
        $hasSiteAccess = $user->can('sites.read') || $user->hasAccessibleSites();
        $employee = $user->employee;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'emailVerified' => $user->hasVerifiedEmail(),
            'roles' => $roles,
            'permissions' => $permissions,
            'hasOrganizationalScopes' => $user->organizationalScopes->isNotEmpty(),
            'hasCustomerAccess' => $hasCustomerAccess,
            'hasSiteAccess' => $hasSiteAccess,
            'employeeStatus' => $employee?->status,
            'onboardingWorkflowStatus' => $employee?->resolveOnboardingWorkflowStatus(),
            'employee' => $employee
                ? [
                    'id' => (string) $employee->id,
                    'contractStartDate' => $employee->contract_start_date?->toDateString(),
                ]
                : null,
        ];
    }

    private function normalizeAuthenticatedEmployeeWorkflow(User $user): void
    {
        $user->loadMissing('employee');

        /** @var Employee|null $employee */
        $employee = $user->employee;

        if (! $employee) {
            return;
        }

        $user->setRelation('employee', $employee->normalizeAuthenticatedOnboardingWorkflow());
    }

    /**
     * Validate primary email/password credentials for either login flow.
     *
     * Always runs the configured password hasher, even when the lookup misses,
     * so the response time does not leak whether an account exists. Otherwise
     * the bcrypt cost (~100 ms) would create a measurable timing oracle.
     *
     * @throws ValidationException
     */
    private function validatePrimaryCredentials(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        $hashToCheck = $user !== null ? $user->password : $this->dummyPasswordHash();
        $passwordValid = Hash::check($password, $hashToCheck);

        if (! $user || ! $passwordValid) {
            $this->activityLogService->logLoginFailed($email, 'invalid_credentials');

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $user;
    }

    /**
     * Return a stable bcrypt placeholder used to neutralize the login timing
     * oracle when no user matches the submitted email address. Cache entries
     * are scoped by hasher config so cost/driver changes regenerate the hash.
     */
    private function dummyPasswordHash(): string
    {
        try {
            return Cache::rememberForever(
                $this->dummyPasswordHashCacheKey(),
                static fn (): string => (string) Hash::make(self::DUMMY_PASSWORD_PLACEHOLDER),
            );
        } catch (Throwable) {
            return self::FALLBACK_DUMMY_PASSWORD_HASH;
        }
    }

    private function dummyPasswordHashCacheKey(): string
    {
        $hashConfig = config('hashing', []);

        if (! is_array($hashConfig)) {
            $hashConfig = ['value' => $hashConfig];
        }

        return self::DUMMY_PASSWORD_HASH_CACHE_PREFIX.':'.hash('sha256', serialize($hashConfig));
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
    private function completeSessionLogin(Request $request, User $user, bool $mfaCompleted = false, ?string $method = null): JsonResponse
    {
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        $this->activityLogService->logLoginSuccess($user);

        if ($mfaCompleted) {
            return response()->json($this->buildCompletedLoginResponse($user, LoginMfaChallengeService::LOGIN_CONTEXT_SESSION, method: $method));
        }

        return response()->json([
            'user' => $this->buildUserAuthorizationData($user),
        ]);
    }

    /**
     * Complete a token login.
     */
    private function completeTokenLogin(
        User $user,
        string $deviceName,
        bool $mfaCompleted = false,
        int $createdStatus = 201,
        ?string $method = null,
    ): JsonResponse {
        $token = $user->issueApiToken($deviceName);

        $this->activityLogService->logLoginSuccess($user);

        if ($mfaCompleted) {
            return response()->json(
                $this->buildCompletedLoginResponse($user, LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN, $token->plainTextToken, $method),
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
     * @return array{user: array{id: string, name: string, email: string, emailVerified: bool, roles: list<string>, permissions: list<string>, hasOrganizationalScopes: bool, hasCustomerAccess: bool, hasSiteAccess: bool}, authentication: array{mode: string, mfa_completed: bool, method?: string}, token?: string}
     */
    private function buildCompletedLoginResponse(User $user, string $mode, ?string $token = null, ?string $method = null): array
    {
        $response = [
            'user' => $this->buildUserAuthorizationData($user),
            'authentication' => [
                'mode' => $mode,
                'mfa_completed' => true,
            ],
        ];

        if ($method !== null) {
            $response['authentication']['method'] = $method;
        }

        if ($token !== null) {
            $response['token'] = $token;
        }

        return $response;
    }

    private function requireBrowserSessionContext(Request $request): ?JsonResponse
    {
        if ($request->attributes->get('sanctum') === true && $request->hasSession()) {
            return null;
        }

        return response()->json([
            'message' => __('This endpoint requires a browser session context. Use the SecPal web app origin to continue.'),
        ], 409);
    }

    private function createPasskeyAuthenticationChallengeResponse(
        ?string $email,
        string $loginContext = LoginMfaChallengeService::LOGIN_CONTEXT_SESSION,
        ?string $deviceName = null,
    ): JsonResponse {
        $user = $this->resolvePasskeyAuthenticationUser($email);

        try {
            $options = $this->passkeyService->buildAuthenticationOptions($user, $email);
        } catch (\RuntimeException $exception) {
            report($exception);

            Log::error('Passkey authentication challenge could not be issued due to missing configuration', [
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => __('Passkey sign-in is currently unavailable. Please try again later or use a different sign-in method.'),
            ], 503);
        }

        $challenge = $this->passkeyChallengeService->createAuthenticationChallenge(
            $options,
            'optional',
            $loginContext,
            $deviceName,
        );

        return response()->json([
            'data' => [
                'challenge_id' => $challenge['challenge_id'],
                'public_key' => $this->passkeyService->formatApiPayload($challenge['public_key']),
                'mediation' => $challenge['mediation'],
                'expires_at' => $challenge['expires_at'],
            ],
        ], 201);
    }

    private function resolvePasskeyAuthenticationUser(?string $email): ?User
    {
        $userQuery = User::query();

        if (is_string($email) && $email !== '') {
            $userQuery->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
        } else {
            $userQuery->whereRaw('1 = 0');
        }

        $user = $userQuery->first();

        // Always execute the passkey_credentials query to align the DB lookup
        // count across all account states. When no real user matched, query
        // against the nil UUID (PASSKEY_AUTHENTICATION_PLACEHOLDER_USER_ID),
        // which must never be assigned to a real user or seeded credential.
        $passkeyCredentials = PasskeyCredential::query()
            ->where('user_id', $user instanceof User ? $user->id : self::PASSKEY_AUTHENTICATION_PLACEHOLDER_USER_ID)
            ->get();

        if (! $user instanceof User) {
            return null;
        }

        $user->setRelation('passkeyCredentials', $passkeyCredentials);

        return $passkeyCredentials->isNotEmpty() ? $user : null;
    }

    private function completePasskeyAuthenticationChallenge(
        PasskeyAuthenticationVerificationRequest $request,
        string $challengeId,
        string $expectedLoginContext,
    ): JsonResponse {
        if (! Str::isUuid($challengeId)) {
            return $this->resourceNotFoundResponse();
        }

        $challenge = $this->passkeyChallengeService->findAuthenticationChallenge($challengeId);

        if ($challenge === null) {
            return $this->resourceNotFoundResponse();
        }

        if ($challenge['login_context'] !== $expectedLoginContext) {
            return response()->json([
                'message' => __('This passkey challenge must be completed from its original login context.'),
            ], 409);
        }

        /** @var array{credential: array<string, mixed>} $validated */
        $validated = $request->validated();

        try {
            $result = $this->passkeyService->verifyAuthentication(
                $challenge['public_key'],
                $validated['credential'],
            );
        } catch (WebauthnException $exception) {
            $this->passkeyChallengeService->forgetAuthenticationChallenge($challengeId);

            throw $this->passkeyCredentialValidationException($exception);
        } catch (Throwable $exception) {
            $this->passkeyChallengeService->forgetAuthenticationChallenge($challengeId);

            report($exception);

            Log::warning('Passkey authentication verification failed with unexpected error', [
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'credential' => ['The passkey credential could not be verified.'],
            ]);
        }

        $this->passkeyChallengeService->forgetAuthenticationChallenge($challengeId);

        if ($expectedLoginContext === LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN) {
            return $this->completeTokenLogin(
                $result['user'],
                $challenge['device_name'] ?? 'api-client',
                mfaCompleted: true,
                createdStatus: 200,
                method: 'passkey',
            );
        }

        return $this->completeSessionLogin($request, $result['user'], mfaCompleted: true, method: 'passkey');
    }

    private function resourceNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'message' => __('Resource not found.'),
        ], 404);
    }

    private function passkeyCredentialValidationException(WebauthnException $exception): ValidationException
    {
        $message = trim($exception->getMessage());

        return ValidationException::withMessages([
            'credential' => [$message !== '' ? $message : 'The passkey credential could not be verified.'],
        ]);
    }

    /**
     * Throw a ValidationException if the submitted TOTP code was recently consumed by the anti-replay cache.
     *
     * @throws ValidationException
     */
    private function throwIfTotpCodeRecentlyUsed(User $user, string $method, string $code): void
    {
        if ($method === 'totp' && $this->mfaService->isTotpCodeRecentlyUsed($user, $code)) {
            throw ValidationException::withMessages([
                'code' => ['This code was already used recently. Please wait for a new code from your authenticator app.'],
            ]);
        }
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
