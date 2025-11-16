<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSecretRequest;
use App\Http\Requests\UpdateSecretRequest;
use App\Models\Secret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SecretController handles CRUD operations for Secrets.
 *
 * All secrets are scoped to the authenticated user's tenant.
 * Users can access secrets they own (owner-based) only.
 * Shared secret access via SecretShare is checked at Policy level.
 */
class SecretController extends Controller
{
    /**
     * Transform Secret model to API response format with plaintext fields.
     *
     * @return array<string, mixed>
     */
    private function transformSecret(Secret $secret): array
    {
        return [
            'id' => $secret->id,
            'title' => $secret->title_plain,
            'username' => $secret->username_plain,
            'password' => $secret->password_plain,
            'url' => $secret->url_plain,
            'notes' => $secret->notes_plain,
            'tags' => $secret->tags,
            'expires_at' => $secret->expires_at?->toIso8601String(),
            'version' => $secret->version,
            'created_at' => $secret->created_at->toIso8601String(),
            'updated_at' => $secret->updated_at->toIso8601String(),
        ];
    }

    /**
     * Display a listing of secrets accessible to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Secret::class);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Query secrets owned by user
        $query = Secret::where('owner_id', $user->id);

        // Pagination
        /** @var int $perPageInput */
        $perPageInput = $request->input('per_page', 15);
        $perPage = min((int) $perPageInput, 100);
        $secrets = $query->paginate($perPage);

        // Transform secrets to include plaintext fields
        $transformedSecrets = $secrets->getCollection()->map(fn (Secret $secret) => $this->transformSecret($secret));

        return response()->json([
            'data' => $transformedSecrets,
            'meta' => [
                'current_page' => $secrets->currentPage(),
                'per_page' => $secrets->perPage(),
                'total' => $secrets->total(),
            ],
        ]);
    }

    /**
     * Store a newly created secret.
     */
    public function store(StoreSecretRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // TODO: Replace with TenantMiddleware that injects tenant_id into request
        // For now, use first available tenant (testing only - NOT production-ready)
        $tenantId = \App\Models\TenantKey::first()?->id;
        if (! $tenantId) {
            return response()->json([
                'error' => 'Tenant resolution not yet implemented. Please contact system administrator.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $secret = new Secret;
        $secret->tenant_id = $tenantId;
        $secret->owner_id = $user->id;
        /** @var string $title */
        $title = $request->input('title');
        $secret->title_plain = $title;
        /** @var string|null $username */
        $username = $request->input('username');
        $secret->username_plain = $username;
        /** @var string|null $password */
        $password = $request->input('password');
        $secret->password_plain = $password;
        /** @var string|null $url */
        $url = $request->input('url');
        $secret->url_plain = $url;
        /** @var string|null $notes */
        $notes = $request->input('notes');
        $secret->notes_plain = $notes;
        /** @var array<string>|null $tags */
        $tags = $request->input('tags');
        $secret->tags = $tags;
        /** @var \Illuminate\Support\Carbon|null $expiresAt */
        $expiresAt = $request->input('expires_at');
        $secret->expires_at = $expiresAt;
        $secret->version = 1;
        $secret->save();

        return response()->json([
            'data' => $this->transformSecret($secret),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified secret.
     */
    public function show(Secret $secret): JsonResponse
    {
        // Authorization handled by SecretPolicy
        $this->authorize('view', $secret);

        return response()->json([
            'data' => $this->transformSecret($secret),
        ]);
    }

    /**
     * Update the specified secret.
     */
    public function update(UpdateSecretRequest $request, Secret $secret): JsonResponse
    {
        // Authorization handled by SecretPolicy
        $this->authorize('update', $secret);

        if ($request->has('title')) {
            /** @var string $title */
            $title = $request->input('title');
            $secret->title_plain = $title;
        }
        if ($request->has('username')) {
            /** @var string|null $username */
            $username = $request->input('username');
            $secret->username_plain = $username;
        }
        if ($request->has('password')) {
            /** @var string|null $password */
            $password = $request->input('password');
            $secret->password_plain = $password;
        }
        if ($request->has('url')) {
            /** @var string|null $url */
            $url = $request->input('url');
            $secret->url_plain = $url;
        }
        if ($request->has('notes')) {
            /** @var string|null $notes */
            $notes = $request->input('notes');
            $secret->notes_plain = $notes;
        }
        if ($request->has('tags')) {
            /** @var array<string>|null $tags */
            $tags = $request->input('tags');
            $secret->tags = $tags;
        }
        if ($request->has('expires_at')) {
            /** @var \Illuminate\Support\Carbon|null $expiresAt */
            $expiresAt = $request->input('expires_at');
            $secret->expires_at = $expiresAt;
        }

        // Increment version on update
        $secret->version++;
        $secret->save();

        return response()->json([
            'data' => $this->transformSecret($secret),
        ]);
    }

    /**
     * Remove the specified secret (soft delete).
     */
    public function destroy(Secret $secret): Response
    {
        // Authorization handled by SecretPolicy
        $this->authorize('delete', $secret);

        $secret->delete();

        return response()->noContent();
    }
}
