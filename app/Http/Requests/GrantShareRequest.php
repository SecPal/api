<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validation for granting secret access.
 *
 * Enforces XOR constraint: EITHER user_id OR role_id must be present, not both.
 */
class GrantShareRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by SecretSharePolicy
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
            'user_id' => [
                'required_without:role_id',
                'nullable',
                'string',
                'uuid',
                'exists:users,id',
            ],
            'role_id' => [
                'required_without:user_id',
                'nullable',
                'integer',
                'exists:roles,id',
            ],
            'permission' => [
                'required',
                'string',
                Rule::in(['read', 'write', 'admin']),
            ],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            // XOR constraint: user_id and role_id cannot both be present
            if ($this->filled('user_id') && $this->filled('role_id')) {
                $validator->errors()->add('user_id', 'Cannot grant to both user and role simultaneously (XOR constraint).');
                $validator->errors()->add('role_id', 'Cannot grant to both user and role simultaneously (XOR constraint).');
            }
        });
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required_without' => 'Either user_id or role_id must be provided.',
            'role_id.required_without' => 'Either user_id or role_id must be provided.',
            'permission.in' => 'Permission must be one of: read, write, admin.',
            'expires_at.after' => 'Expiration date must be in the future.',
        ];
    }
}
