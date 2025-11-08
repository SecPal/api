<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\TemporalRoleUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExtendRoleRequest extends FormRequest
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
            'valid_until' => 'required|date|after:now',
            'reason' => 'nullable|string|max:500',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $userId = $this->route('id');
            $roleName = $this->route('role');

            // Find current assignment
            $assignment = TemporalRoleUser::whereHas('role', function ($query) use ($roleName) {
                $query->where('name', $roleName);
            })
                ->where('model_id', $userId)
                ->where('model_type', 'App\\Models\\User')
                ->first();

            // Validate new valid_until is after current valid_until
            if ($assignment && $this->input('valid_until')) {
                $newValidUntil = \Carbon\Carbon::parse($this->input('valid_until'));
                if ($newValidUntil->lessThanOrEqualTo($assignment->valid_until)) {
                    $validator->errors()->add(
                        'valid_until',
                        'New expiration date must be after the current expiration date.'
                    );
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
            'valid_until.required' => 'New expiration date is required.',
            'valid_until.after' => 'Expiration date must be in the future.',
            'reason.max' => 'Reason must not exceed 500 characters.',
        ];
    }
}
