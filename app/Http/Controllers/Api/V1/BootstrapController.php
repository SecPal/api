<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetBootstrapConfigurationRequest;
use App\Support\AndroidPushRuntimeConfiguration;
use App\Support\BootstrapContract;
use App\Support\Concerns\InteractsWithConfigValues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

class BootstrapController extends Controller
{
    use InteractsWithConfigValues;

    private const DEFAULT_RETRY_AFTER_SECONDS = 60;

    public function show(
        GetBootstrapConfigurationRequest $request,
        AndroidPushRuntimeConfiguration $androidPushRuntimeConfiguration,
    ): JsonResponse {
        if (! $this->bootstrapPublicEnabled()) {
            return $this->configurationUnavailableResponse();
        }

        $apiBaseUrl = $this->apiBaseUrl();
        $displayName = $this->instanceDisplayName();
        $minimumSupportedAppVersion = $this->minimumSupportedAppVersion();
        $minimumSupportedAppBuild = $this->minimumSupportedAppBuild();
        if ($apiBaseUrl === null
            || $displayName === null
            || $minimumSupportedAppVersion === null
            || $minimumSupportedAppBuild === null) {
            return $this->invalidStateResponse($this->missingRequiredFields(
                $apiBaseUrl,
                $displayName,
                $minimumSupportedAppVersion,
                $minimumSupportedAppBuild,
            ));
        }

        $androidPushMissingFields = $androidPushRuntimeConfiguration->missingFields();

        if ($androidPushMissingFields !== []) {
            return $this->invalidStateResponse($androidPushMissingFields);
        }

        if ($this->clientIsBelowMinimumSupportedVersion(
            $request->appVersion(),
            $request->appBuild(),
            $minimumSupportedAppVersion,
            $minimumSupportedAppBuild,
        )) {
            return $this->unsupportedClientVersionResponse(
                $request->appVersion(),
                $request->appBuild(),
                $minimumSupportedAppVersion,
                $minimumSupportedAppBuild,
            );
        }

        $data = [
            'client_platform' => $request->clientPlatform(),
            'api_base_url' => $apiBaseUrl,
            'instance' => [
                'display_name' => $displayName,
            ],
            'compatibility' => [
                'bootstrap_version' => BootstrapContract::VERSION,
                'schema_version' => BootstrapContract::SCHEMA_VERSION,
                'minimum_supported_app_version' => $minimumSupportedAppVersion,
                'minimum_supported_app_build' => $minimumSupportedAppBuild,
            ],
            'features' => [
                'password_login' => $this->booleanConfig('bootstrap.features.password_login', true),
                'passkey_login' => $this->booleanConfig('bootstrap.features.passkey_login', true),
                'managed_android_enrollment' => $this->booleanConfig('bootstrap.features.managed_android_enrollment', false),
                'android_push' => $androidPushRuntimeConfiguration->isEnabled(),
            ],
        ];

        if ($androidPushRuntimeConfiguration->isEnabled()) {
            $androidPushMetadata = $androidPushRuntimeConfiguration->publicMetadata();

            if ($androidPushMetadata === null) {
                throw new RuntimeException('Android push metadata is enabled but could not be assembled; this is a deployment configuration error.');
            }

            $data['android_push'] = $androidPushMetadata;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * @return array<int, string>
     */
    private function missingRequiredFields(
        ?string $apiBaseUrl,
        ?string $displayName,
        ?string $minimumSupportedAppVersion,
        ?int $minimumSupportedAppBuild,
    ): array {
        $missingFields = [];

        if ($apiBaseUrl === null) {
            $missingFields[] = 'api_base_url';
        }

        if ($displayName === null) {
            $missingFields[] = 'instance.display_name';
        }

        if ($minimumSupportedAppVersion === null) {
            $missingFields[] = 'compatibility.minimum_supported_app_version';
        }

        if ($minimumSupportedAppBuild === null) {
            $missingFields[] = 'compatibility.minimum_supported_app_build';
        }

        return $missingFields;
    }

    /**
     * @param  array<int, string>  $missingFields
     */
    private function invalidStateResponse(array $missingFields): JsonResponse
    {
        return response()->json([
            'message' => __('Public bootstrap configuration is incomplete for this deployment.'),
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => $missingFields,
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function unsupportedClientVersionResponse(
        string $providedAppVersion,
        int $providedAppBuild,
        string $minimumSupportedAppVersion,
        int $minimumSupportedAppBuild,
    ): JsonResponse {
        return response()->json([
            'message' => __('This SecPal deployment requires app version :version (build :build) or newer before login may proceed.', [
                'version' => $minimumSupportedAppVersion,
                'build' => $minimumSupportedAppBuild,
            ]),
            'code' => 'UNSUPPORTED_CLIENT_VERSION',
            'details' => [
                'provided_app_version' => $providedAppVersion,
                'provided_app_build' => $providedAppBuild,
                'minimum_supported_app_version' => $minimumSupportedAppVersion,
                'minimum_supported_app_build' => $minimumSupportedAppBuild,
                'bootstrap_version' => BootstrapContract::VERSION,
            ],
        ], 426);
    }

    private function configurationUnavailableResponse(): JsonResponse
    {
        $retryable = $this->booleanConfig('bootstrap.retryable', true);
        $retryAfterSeconds = $this->retryAfterSeconds();
        $details = [
            'retryable' => $retryable,
        ];
        $headers = [];

        if ($retryable) {
            $details['retry_after_seconds'] = $retryAfterSeconds;
            $headers['Retry-After'] = (string) $retryAfterSeconds;
        }

        return response()->json([
            'message' => __('Public bootstrap configuration is temporarily unavailable.'),
            'code' => 'BOOTSTRAP_CONFIG_UNAVAILABLE',
            'details' => $details,
        ], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
    }

    private function clientIsBelowMinimumSupportedVersion(
        string $providedAppVersion,
        int $providedAppBuild,
        string $minimumSupportedAppVersion,
        int $minimumSupportedAppBuild,
    ): bool {
        return version_compare($providedAppVersion, $minimumSupportedAppVersion, '<')
            || (
                version_compare($providedAppVersion, $minimumSupportedAppVersion, '==')
                && $providedAppBuild < $minimumSupportedAppBuild
            );
    }

    private function bootstrapPublicEnabled(): bool
    {
        return $this->booleanConfig('bootstrap.public_enabled', false);
    }

    private function retryAfterSeconds(): int
    {
        $value = config('bootstrap.retry_after_seconds', self::DEFAULT_RETRY_AFTER_SECONDS);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return self::DEFAULT_RETRY_AFTER_SECONDS;
    }

    private function minimumSupportedAppVersion(): ?string
    {
        return $this->trimmedStringConfig('bootstrap.minimum_supported_app_version');
    }

    private function minimumSupportedAppBuild(): ?int
    {
        $value = config('bootstrap.minimum_supported_app_build');

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function instanceDisplayName(): ?string
    {
        $displayName = $this->trimmedStringConfig('bootstrap.instance_display_name');

        if ($displayName !== null) {
            return $displayName;
        }

        $appName = $this->trimmedStringConfig('app.name');

        if ($appName === null || $appName === 'Laravel') {
            return null;
        }

        return $appName;
    }

    private function apiBaseUrl(): ?string
    {
        $configuredUrl = $this->trimmedStringConfig('app.url');

        if ($configuredUrl === null || $configuredUrl === 'http://localhost') {
            return null;
        }

        $components = parse_url($configuredUrl);

        if ($components === false
            || ! isset($components['scheme'], $components['host'])
            || isset($components['user'])
            || isset($components['pass'])
            || isset($components['query'])
            || isset($components['fragment'])) {
            return null;
        }

        $host = $components['host'];

        if (! is_string($host) || $host === '' || strtolower($host) === 'localhost') {
            return null;
        }

        $authority = $host;

        if (isset($components['port']) && is_int($components['port'])) {
            $authority .= ':'.$components['port'];
        }

        $rawPath = rtrim((string) ($components['path'] ?? ''), '/');

        if ($rawPath !== '' && $rawPath !== '/v1') {
            return null;
        }

        return strtolower((string) $components['scheme']).'://'.$authority.'/v1';
    }
}
