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
     * SPA Login - Authenticate user and start session (for web SPA).
     *
     * Uses Laravel's session-based authentication with httpOnly cookies.
     * This is the preferred method for browser-based SPAs.
     *
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();

        // Use web guard explicitly for session-based auth
        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * SPA Logout - End session (for web SPA).
     *
     * Note: This requires the request to have a session.
     * For token-based logout, use the logout() method.
     */
    public function logoutSession(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
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
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $validated['device_name'] ?? 'api-client';
        $token = $user->createToken($deviceName);

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

        /** @var \Laravel\Sanctum\PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        // Token might already be deleted/invalid (e.g., concurrent logout)
        if ($token !== null) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Token revoked successfully.',
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
            'message' => 'All tokens revoked successfully.',
        ]);
    }

    /**
     * Get the authenticated user's information.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
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
            'message' => 'Password reset email sent if account exists',
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
                'message' => 'Invalid or expired reset token',
            ], 400);
        }

        // Get stored token record
        /** @var object{email: string, token: string, created_at: string}|null $tokenRecord */
        $tokenRecord = DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

        if (! $tokenRecord) {
            return response()->json([
                'message' => 'Invalid or expired reset token',
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
                'message' => 'Invalid or expired reset token',
            ], 400);
        }

        // Verify token
        if (! Hash::check($validated['token'], $tokenRecord->token)) {
            return response()->json([
                'message' => 'Invalid or expired reset token',
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
            'message' => 'Password has been reset successfully',
        ]);
    }
}
