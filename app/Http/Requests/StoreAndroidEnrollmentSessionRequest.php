<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAndroidEnrollmentSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('android_enrollment.write') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'device_label' => ['nullable', 'string', 'max:120'],
            'enrollment_mode' => ['nullable', 'in:device_owner'],
            'update_channel' => ['required', 'in:managed_device,direct_apk,github_release,obtainium'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'provisioning_profile' => ['required', 'array'],
            'provisioning_profile.kiosk_mode_enabled' => ['required', 'boolean'],
            'provisioning_profile.lock_task_enabled' => ['required', 'boolean'],
            'provisioning_profile.allow_phone' => ['required', 'boolean'],
            'provisioning_profile.allow_sms' => ['required', 'boolean'],
            'provisioning_profile.prefer_gesture_navigation' => ['required', 'boolean'],
            'provisioning_profile.allowed_packages' => ['required', 'array'],
            'provisioning_profile.allowed_packages.*' => ['string', 'regex:/^[A-Za-z0-9_.]+$/'],
        ];
    }

    /**
     * @return array{
     *     device_label?: string,
     *     enrollment_mode?: string,
     *     update_channel: string,
     *     provisioning_profile: array<string, mixed>,
     *     expires_in_minutes?: int,
     *     notes?: string
     * }
     */
    public function validatedPayload(): array
    {
        $validated = $this->validated();
        $provisioningProfile = $validated['provisioning_profile'] ?? [];
        $normalizedProvisioningProfile = [];

        if (is_array($provisioningProfile)) {
            foreach ($provisioningProfile as $key => $value) {
                if (is_string($key)) {
                    $normalizedProvisioningProfile[$key] = $value;
                }
            }
        }

        $payload = [
            'update_channel' => $this->string('update_channel')->toString(),
            'provisioning_profile' => $normalizedProvisioningProfile,
        ];

        if ($this->filled('device_label')) {
            $payload['device_label'] = $this->string('device_label')->toString();
        }

        if ($this->filled('enrollment_mode')) {
            $payload['enrollment_mode'] = $this->string('enrollment_mode')->toString();
        }

        if ($this->filled('expires_in_minutes')) {
            $payload['expires_in_minutes'] = $this->integer('expires_in_minutes');
        }

        if ($this->filled('notes')) {
            $payload['notes'] = $this->string('notes')->toString();
        }

        return $payload;
    }
}
