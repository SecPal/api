<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeAndroidBootstrapTokenRequest;
use App\Http\Requests\RevokeAndroidEnrollmentSessionRequest;
use App\Http\Requests\StoreAndroidEnrollmentSessionRequest;
use App\Models\AndroidEnrollmentSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AndroidEnrollmentSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        /** @var array{page?: int, per_page?: int, status?: string|null} $validated */
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'in:pending,exchanged,revoked,expired'],
        ]);

        $query = AndroidEnrollmentSession::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest();

        $status = $validated['status'] ?? null;
        if (is_string($status) && $status !== '') {
            if ($status === 'pending') {
                $query->whereNull('revoked_at')->whereNull('exchanged_at')->where('bootstrap_token_expires_at', '>', now());
            } elseif ($status === 'exchanged') {
                $query->whereNotNull('exchanged_at');
            } elseif ($status === 'revoked') {
                $query->whereNotNull('revoked_at');
            } elseif ($status === 'expired') {
                $query->whereNull('revoked_at')->whereNull('exchanged_at')->where('bootstrap_token_expires_at', '<=', now());
            }
        }

        $sessions = $query->paginate((int) ($validated['per_page'] ?? 15));

        return response()->json([
            'data' => array_map(fn (AndroidEnrollmentSession $session): array => $this->transformSession($session), $sessions->items()),
            'links' => [
                'first' => $sessions->url(1),
                'last' => $sessions->url($sessions->lastPage()),
                'prev' => $sessions->previousPageUrl(),
                'next' => $sessions->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'from' => $sessions->firstItem(),
                'last_page' => $sessions->lastPage(),
                'path' => $sessions->path(),
                'per_page' => $sessions->perPage(),
                'to' => $sessions->lastItem(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    public function store(StoreAndroidEnrollmentSessionRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $issued = AndroidEnrollmentSession::generate($user, $request->validatedPayload());
        $session = $issued['model'];

        activity('android_provisioning')
            ->causedBy($user)
            ->performedOn($session)
            ->withProperties([
                'event' => 'android_enrollment_session_created',
                'update_channel' => $session->update_channel,
                'enrollment_mode' => $session->enrollment_mode,
            ])
            ->log('Created Android enrollment session');

        return response()->json([
            'data' => [
                'session' => $this->transformSession($session),
                'provisioning_qr_payload' => $session->provisioningQrPayload($issued['plain']),
            ],
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $model = AndroidEnrollmentSession::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($session);

        return response()->json([
            'data' => $this->transformSession($model),
        ]);
    }

    public function revoke(RevokeAndroidEnrollmentSessionRequest $request, string $session): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $model = AndroidEnrollmentSession::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($session);

        if (! $model->isPending()) {
            return response()->json([
                'message' => __('Android enrollment session can no longer be revoked.'),
                'code' => 'CONFLICT',
            ], Response::HTTP_CONFLICT);
        }

        $reason = $request->string('reason')->toString();
        $model->revoke($reason);

        activity('android_provisioning')
            ->causedBy($user)
            ->performedOn($model)
            ->withProperties([
                'event' => 'android_enrollment_session_revoked',
                'reason' => $reason,
            ])
            ->log('Revoked Android enrollment session');

        return response()->json([
            'data' => $this->transformSession($model->fresh() ?? $model),
        ]);
    }

    public function exchange(ExchangeAndroidBootstrapTokenRequest $request): JsonResponse
    {
        $plainToken = $request->string('bootstrap_token')->toString();
        $session = AndroidEnrollmentSession::lookupByPlainToken($plainToken);

        if (! $session instanceof AndroidEnrollmentSession) {
            return response()->json([
                'message' => __('Invalid bootstrap token.'),
                'errors' => [
                    'bootstrap_token' => [__('The provided bootstrap token is invalid.')],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $exchanged = DB::transaction(function () use ($session, $request): bool {
            /** @var AndroidEnrollmentSession|null $locked */
            $locked = AndroidEnrollmentSession::lockForUpdate()->find($session->id);

            if (! $locked instanceof AndroidEnrollmentSession || ! $locked->isPending()) {
                return false;
            }

            $locked->markAsExchanged(
                $request->ip() ?? 'unknown',
                $request->userAgent() ?? 'unknown',
            );

            return true;
        });

        if (! $exchanged) {
            return response()->json([
                'message' => __('Android bootstrap token can no longer be exchanged.'),
                'code' => 'CONFLICT',
            ], Response::HTTP_CONFLICT);
        }

        activity('android_provisioning')
            ->performedOn($session)
            ->withProperties([
                'event' => 'android_bootstrap_exchanged',
                'package_name' => $request->string('package_name')->toString(),
                'package_version_name' => $request->filled('package_version_name') ? $request->string('package_version_name')->toString() : null,
                'package_version_code' => $request->filled('package_version_code') ? $request->integer('package_version_code') : null,
                'device_name' => $request->filled('device_name') ? $request->string('device_name')->toString() : null,
            ])
            ->log('Completed Android bootstrap exchange');

        return response()->json([
            'data' => ($session->fresh() ?? $session)->bootstrapConfiguration(),
        ]);
    }

    /** @return array<string, mixed> */
    private function transformSession(AndroidEnrollmentSession $session): array
    {
        return [
            'id' => $session->id,
            'device_label' => $session->device_label,
            'status' => $session->status,
            'enrollment_mode' => $session->enrollment_mode,
            'update_channel' => $session->update_channel,
            'release_metadata_url' => $session->release_metadata_url,
            'provisioning_profile' => $session->provisioning_profile,
            'bootstrap_token_expires_at' => \App\Support\ApiTimestamp::format($session->bootstrap_token_expires_at),
            'bootstrap_token_last_eight' => $session->bootstrap_token_lookup_hash !== null
                ? strtoupper(substr($session->bootstrap_token_lookup_hash, -8))
                : null,
            'exchanged_at' => \App\Support\ApiTimestamp::nullable($session->exchanged_at),
            'revoked_at' => \App\Support\ApiTimestamp::nullable($session->revoked_at),
            'revocation_reason' => $session->revocation_reason,
            'notes' => $session->notes,
            'created_at' => \App\Support\ApiTimestamp::nullable($session->created_at),
            'updated_at' => \App\Support\ApiTimestamp::nullable($session->updated_at),
        ];
    }
}
