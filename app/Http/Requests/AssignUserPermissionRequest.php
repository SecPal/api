<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Permission;

/**
 * Validation for direct permission assignment to users.
 *
 * Validates:
 * - permissions: Array of permission names (must exist)
 * - valid_from: Optional timestamp (must be before valid_until)
 * - valid_until: Optional timestamp
 * - reason: Optional justification text
 */
class AssignUserPermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization handled by Gate in controller
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! is_string($value)) {
                        $fail('The permission must be a string.');

                        return;
                    }

                    if (! Permission::where('name', $value)->where('guard_name', 'sanctum')->exists()) {
                        $fail("The permission '{$value}' does not exist.");
                    }
                },
            ],
            'valid_from' => ['nullable', 'date', 'before_or_equal:valid_until'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.required' => 'At least one permission must be provided.',
            'permissions.array' => 'Permissions must be an array.',
            'permissions.min' => 'At least one permission must be provided.',
            'permissions.*.required' => 'Permission name cannot be empty.',
            'permissions.*.string' => 'Permission name must be a string.',
            'valid_from.before_or_equal' => 'Valid from date must be before or equal to valid until date.',
            'valid_until.after' => 'Valid until date must be after valid from date.',
            'reason.max' => 'Reason cannot exceed 1000 characters.',
        ];
    }
}
