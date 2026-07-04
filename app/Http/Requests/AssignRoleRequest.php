<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'exists:roles,name'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'auto_revoke' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Configure the validator instance to add custom date validation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var string|null $validFrom */
            $validFrom = $this->input('valid_from');
            /** @var string|null $validUntil */
            $validUntil = $this->input('valid_until');

            // Only validate date order if both are provided
            if ($validFrom && $validUntil) {
                $from = Carbon::parse($validFrom);
                $until = Carbon::parse($validUntil);

                if ($from->greaterThan($until)) {
                    $validator->errors()->add('valid_until', 'End date must be after start date.');
                }
            }
        });
    }

    /**
     * Get custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.required' => 'Role name is required.',
            'role.exists' => 'The specified role does not exist.',
            'valid_from.before_or_equal' => 'Start date must be before or equal to end date.',
            'valid_until.after' => 'End date must be after start date.',
            'reason.max' => 'Reason must not exceed 500 characters.',
        ];
    }
}
