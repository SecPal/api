<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertPushDeviceRegistrationRequest;
use App\Models\User;
use App\Services\PushDeviceRegistrationService;
use App\Support\AndroidPushRuntimeConfiguration;
use App\Support\BootstrapContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushDeviceRegistrationController extends Controller
{
    public function __construct(
        private readonly AndroidPushRuntimeConfiguration $androidPushRuntimeConfiguration,
        private readonly PushDeviceRegistrationService $pushDeviceRegistrationService,
    ) {}

    public function upsert(UpsertPushDeviceRegistrationRequest $request, string $installationId): JsonResponse
    {
        if (! $this->androidPushRuntimeConfiguration->isEnabled()) {
            return $this->unsupportedConfigurationResponse();
        }

        $missingFields = $this->androidPushRuntimeConfiguration->missingFields();

        if ($missingFields !== []) {
            return $this->invalidConfigurationResponse($missingFields);
        }

        $providedRevision = $request->integer('runtime.push_metadata_revision');
        $expectedRevision = $this->androidPushRuntimeConfiguration->metadataRevision();

        if ($expectedRevision === null) {
            return $this->invalidConfigurationResponse([
                'android_push.metadata_revision',
            ]);
        }

        if ($providedRevision !== $expectedRevision) {
            return $this->runtimeStateConflictResponse($providedRevision, $expectedRevision);
        }

        /** @var User $user */
        $user = $request->user();
        $result = $this->pushDeviceRegistrationService->upsert($user, $installationId, $request->validated());
        $registration = $result['registration'];
        $status = $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK;

        return response()->json([
            'data' => $registration->toApiArray(),
        ], $status);
    }

    public function destroy(Request $request, string $installationId): JsonResponse
    {
        if (! $this->androidPushRuntimeConfiguration->isEnabled()) {
            return $this->unsupportedConfigurationResponse();
        }

        /** @var User $user */
        $user = $request->user();
        $revocation = $this->pushDeviceRegistrationService->revoke($user, $installationId);

        if ($revocation === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $revocation,
        ]);
    }

    private function unsupportedConfigurationResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This deployment does not accept authenticated Android push-device registrations.',
            'code' => 'ANDROID_PUSH_UNSUPPORTED',
            'details' => [
                'feature_flag' => 'android_push',
                'provider' => BootstrapContract::ANDROID_PUSH_PROVIDER,
                'retryable' => false,
            ],
        ], Response::HTTP_CONFLICT);
    }

    private function runtimeStateConflictResponse(int $providedRevision, int $expectedRevision): JsonResponse
    {
        return response()->json([
            'message' => 'Android push runtime metadata changed; refresh bootstrap before retrying this registration.',
            'code' => 'PUSH_RUNTIME_STATE_INVALID',
            'details' => [
                'bootstrap_version' => BootstrapContract::VERSION,
                'schema_version' => BootstrapContract::SCHEMA_VERSION,
                'provider' => BootstrapContract::ANDROID_PUSH_PROVIDER,
                'provided_push_metadata_revision' => $providedRevision,
                'expected_push_metadata_revision' => $expectedRevision,
            ],
        ], Response::HTTP_CONFLICT);
    }

    /**
     * @param  array<int, string>  $missingFields
     */
    private function invalidConfigurationResponse(array $missingFields): JsonResponse
    {
        return response()->json([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => $missingFields,
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
