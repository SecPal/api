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
}
