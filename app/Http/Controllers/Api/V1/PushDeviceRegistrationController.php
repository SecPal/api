<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertPushDeviceRegistrationRequest;
use App\Models\User;
use App\Services\PushDeviceRegistrationService;
use App\Support\BootstrapContract;
use App\Support\NotificationChannelRuntimeConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushDeviceRegistrationController extends Controller
{
    public function __construct(
        private readonly NotificationChannelRuntimeConfiguration $notificationChannelRuntimeConfiguration,
        private readonly PushDeviceRegistrationService $pushDeviceRegistrationService,
    ) {}

    public function upsert(UpsertPushDeviceRegistrationRequest $request, string $installationId): JsonResponse
    {
        $channel = $request->string('channel')->toString();

        if (! $this->notificationChannelRuntimeConfiguration->isEnabled($channel)) {
            return $this->unsupportedConfigurationResponse($channel);
        }

        $missingFields = $this->notificationChannelRuntimeConfiguration->missingFieldsFor($channel);

        if ($missingFields !== []) {
            return $this->invalidConfigurationResponse($missingFields);
        }

        $providedRevision = $request->integer('runtime.metadata_revision');
        $expectedRevision = $this->notificationChannelRuntimeConfiguration->metadataRevision($channel);

        if ($expectedRevision === null) {
            return $this->invalidConfigurationResponse([
                'notification_channels.'.$channel.'.metadata_revision',
            ]);
        }

        if ($providedRevision !== $expectedRevision) {
            return $this->runtimeStateConflictResponse($channel, $providedRevision, $expectedRevision);
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

    private function unsupportedConfigurationResponse(string $channel): JsonResponse
    {
        return response()->json([
            'message' => __('This deployment does not accept authenticated notification installations for the requested channel.'),
            'code' => 'NOTIFICATION_CHANNEL_UNSUPPORTED',
            'details' => [
                'channel' => $channel,
                'retryable' => false,
            ],
        ], Response::HTTP_CONFLICT);
    }

    private function runtimeStateConflictResponse(string $channel, int $providedRevision, int $expectedRevision): JsonResponse
    {
        return response()->json([
            'message' => __('Notification runtime metadata changed; refresh bootstrap before retrying this installation update.'),
            'code' => 'NOTIFICATION_RUNTIME_STATE_INVALID',
            'details' => [
                'bootstrap_version' => BootstrapContract::VERSION,
                'schema_version' => BootstrapContract::SCHEMA_VERSION,
                'channel' => $channel,
                'provided_metadata_revision' => $providedRevision,
                'expected_metadata_revision' => $expectedRevision,
            ],
        ], Response::HTTP_CONFLICT);
    }

    /**
     * @param  array<int, string>  $missingFields
     */
    private function invalidConfigurationResponse(array $missingFields): JsonResponse
    {
        return response()->json([
            'message' => __('Public bootstrap configuration is incomplete for this deployment.'),
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => $missingFields,
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
