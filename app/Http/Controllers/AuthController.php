<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\PasswordResetRequestRequest;
use App\Http\Requests\TokenRequest;
use App\Http\Requests\UpdateUserLanguageRequest;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        private ActivityLogService $activityLogService
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
     * Security note: Users can explicitly log out via logoutSession()
     * (e.g., /v1/auth/session/logout) to revoke the remember token.
     *
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();

        // Use web guard explicitly for session-based auth
        // remember=true for PWA - maintains long-lived session via remember_token cookie
        if (! Auth::guard('web')->attempt($credentials, remember: true)) {
            // Log failed login attempt (service will find tenant_id by email)
            $this->activityLogService->logLoginFailed(
                $credentials['email'],
                'invalid_credentials'
            );

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        // Log successful login
        $this->activityLogService->logLoginSuccess($user);

        return response()->json([
            'user' => $this->buildUserAuthorizationData($user),
        ]);
    }

    /**
     * SPA Logout - End session (for web SPA).
     *
     * Note: This requires the request to have a session.
     * For token-based logout, use the logout() method.
     *
     * This also clears the remember_token to fully revoke the session,
     * preventing automatic session restoration on subsequent requests.
     */
    public function logoutSession(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        // Log logout before clearing session
        if ($user) {
            $this->activityLogService->logLogout($user);
        }

        // Clear remember token to prevent automatic session restoration
        if ($user) {
            $user->forceFill(['remember_token' => null])->save();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => __('Logged out successfully'),
        ]);
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

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            // Log failed token generation attempt (service will find tenant_id by email)
            $this->activityLogService->logLoginFailed(
                $validated['email'],
                'invalid_credentials'
            );

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $validated['device_name'] ?? 'api-client';
        $token = $user->createToken($deviceName);

        // Log successful token generation (API login)
        $this->activityLogService->logLoginSuccess($user);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    /**
     * Revoke the current user's access token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Log logout before revoking token
        $this->activityLogService->logLogout($user);

        /** @var \Laravel\Sanctum\PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        // Token might already be deleted/invalid (e.g., concurrent logout)
        if ($token !== null) {
            $token->delete();
        }

        return response()->json([
            'message' => __('Token revoked successfully'),
        ]);
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
     *
     * The hasOrganizationalScopes flag is used by the frontend to determine
     * whether to show organization/customer management navigation items.
     *
     * Note: Admin users have maximum organizational scopes (0-255) granting
     * access to all leadership levels and non-leadership employees.
     *
     * @return array{id: string, name: string, email: string, roles: list<string>, permissions: list<string>, hasOrganizationalScopes: bool}
     */
    private function buildUserAuthorizationData(User $user): array
    {
        // Eager load relationships to reduce database queries
        $user->load(['roles', 'permissions', 'organizationalScopes']);

        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->toArray();

        /** @var list<string> $permissions */
        $permissions = $user->getAllPermissions()->pluck('name')->toArray();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles,
            'permissions' => $permissions,
            'hasOrganizationalScopes' => $user->organizationalScopes->isNotEmpty(),
        ];
    }
}
