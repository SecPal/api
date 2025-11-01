<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Generate a new API token for the user.
     *
     * @throws ValidationException
     */
    public function token(Request $request): JsonResponse
    {
        /** @var array{email: string, password: string, device_name?: string} $validated */
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'string|max:255',
        ]);

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
        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Token revoked successfully.',
        ]);
    }

    /**
     * Revoke all of the user's access tokens.
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
}
