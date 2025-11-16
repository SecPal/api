<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantShareRequest;
use App\Models\Secret;
use App\Models\SecretShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * SecretShareController handles sharing secrets with users and roles.
 *
 * Implements access control via SecretShare model:
 * - Grant read/write/admin to users OR roles (XOR)
 * - Optional expiration dates
 * - Only secret owner can grant/revoke
 */
class SecretShareController extends Controller
{
    /**
     * List all shares for a secret.
     */
    public function index(Secret $secret): JsonResponse
    {
        // Authorization
        $this->authorize('viewAny', [SecretShare::class, $secret]);

        // Query active (non-expired) shares
        $shares = SecretShare::where('secret_id', $secret->id)
            ->active()
            ->get();

        // Transform to API response
        $data = $shares->map(fn (SecretShare $share) => $this->transformShare($share));

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Grant access to a secret.
     */
    public function store(GrantShareRequest $request, Secret $secret): JsonResponse
    {
        // Authorization
        $this->authorize('create', [SecretShare::class, $secret]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Create share
        $share = SecretShare::create([
            'secret_id' => $secret->id,
            'user_id' => $request->input('user_id'),
            'role_id' => $request->input('role_id'),
            'permission' => $request->input('permission'),
            'granted_by' => $user->id,
            'granted_at' => now(),
            'expires_at' => $request->input('expires_at'),
        ]);

        return response()->json([
            'data' => $this->transformShare($share),
        ], Response::HTTP_CREATED);
    }

    /**
     * Revoke a share.
     */
    public function destroy(Secret $secret, SecretShare $share): Response
    {
        // Authorization
        $this->authorize('delete', [SecretShare::class, $secret, $share]);

        // Delete share
        $share->delete();

        return response()->noContent();
    }

    /**
     * Transform SecretShare to API response format.
     *
     * @return array<string, mixed>
     */
    private function transformShare(SecretShare $share): array
    {
        return [
            'id' => $share->id,
            'secret_id' => $share->secret_id,
            'user_id' => $share->user_id,
            'role_id' => $share->role_id,
            'permission' => $share->permission,
            'granted_by' => $share->granted_by,
            'granted_at' => $share->granted_at->toIso8601String(),
            'expires_at' => $share->expires_at?->toIso8601String(),
        ];
    }
}
