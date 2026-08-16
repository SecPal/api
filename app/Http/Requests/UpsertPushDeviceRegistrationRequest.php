<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\BootstrapContract;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertPushDeviceRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $this->setNestedInteger($payload, ['registration', 'app', 'package_version_code']);
        $this->setNestedInteger($payload, ['registration', 'device', 'sdk_int']);
        $this->setNestedInteger($payload, ['registration', 'subscription', 'expiration_time']);
        $this->setNestedInteger($payload, ['runtime', 'metadata_revision']);

        $this->replace($payload);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $channel = $this->input('channel');
        $androidChannelRequested = $channel === BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM;
        $webPushChannelRequested = $channel === BootstrapContract::NOTIFICATION_CHANNEL_WEB_PUSH;

        return [
            'channel' => ['required', 'string', Rule::in(BootstrapContract::NOTIFICATION_CHANNELS)],
            'installation_name' => ['required', 'string', 'max:120'],
            'lifecycle_event' => ['required', 'string', Rule::in(BootstrapContract::NOTIFICATION_INSTALLATION_LIFECYCLE_EVENTS)],
            'runtime' => ['required', 'array'],
            'runtime.bootstrap_version' => ['required', 'string', Rule::in([BootstrapContract::VERSION])],
            'runtime.schema_version' => ['required', 'integer:strict', Rule::in([BootstrapContract::SCHEMA_VERSION])],
            'runtime.metadata_revision' => ['required', 'integer', 'min:1'],
            'registration' => ['required', 'array'],
            'registration.push_token' => [Rule::requiredIf($androidChannelRequested), 'string', 'min:32', 'max:4096'],
            'registration.app' => [Rule::requiredIf($androidChannelRequested), 'array'],
            'registration.app.package_name' => [Rule::requiredIf($androidChannelRequested), 'string', Rule::in(['app.secpal'])],
            'registration.app.package_version_name' => ['nullable', 'string', 'max:50'],
            'registration.app.package_version_code' => ['nullable', 'integer', 'min:1'],
            'registration.device' => ['nullable', 'array'],
            'registration.device.manufacturer' => ['nullable', 'string', 'max:120'],
            'registration.device.model' => ['nullable', 'string', 'max:120'],
            'registration.device.android_version' => ['nullable', 'string', 'max:30'],
            'registration.device.sdk_int' => ['nullable', 'integer', 'min:21'],
            'registration.browser' => [Rule::requiredIf($webPushChannelRequested), 'array'],
            'registration.browser.browser_name' => [Rule::requiredIf($webPushChannelRequested), 'string', 'max:80'],
            'registration.browser.browser_version' => ['nullable', 'string', 'max:50'],
            'registration.browser.service_worker_scope' => ['nullable', 'string', 'max:255'],
            'registration.subscription' => [Rule::requiredIf($webPushChannelRequested), 'array'],
            'registration.subscription.endpoint' => [Rule::requiredIf($webPushChannelRequested), 'url', 'max:2048', $this->httpsEndpointRule()],
            'registration.subscription.expiration_time' => ['nullable', 'integer', 'min:0'],
            'registration.subscription.keys' => [Rule::requiredIf($webPushChannelRequested), 'array'],
            'registration.subscription.keys.p256dh' => [Rule::requiredIf($webPushChannelRequested), 'string', 'min:16', 'max:255'],
            'registration.subscription.keys.auth' => [Rule::requiredIf($webPushChannelRequested), 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * @return Closure(string, mixed, Closure(string): void): void
     */
    private function httpsEndpointRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || ! is_string($value)) {
                return;
            }

            $scheme = parse_url($value, PHP_URL_SCHEME);

            if (! is_string($scheme) || strtolower($scheme) !== 'https') {
                $fail('The '.$attribute.' field must be a valid HTTPS URL.');
            }
        };
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $path
     */
    private function setNestedInteger(array &$payload, array $path): void
    {
        $target = &$payload;
        $lastIndex = array_key_last($path);

        foreach ($path as $index => $segment) {
            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return;
            }

            if ($index === $lastIndex) {
                if (is_string($target[$segment]) && filter_var($target[$segment], FILTER_VALIDATE_INT) !== false) {
                    $target[$segment] = (int) $target[$segment];
                }

                return;
            }

            $target = &$target[$segment];
        }
    }
}
