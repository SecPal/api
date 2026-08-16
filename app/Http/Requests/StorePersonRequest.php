<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StorePersonRequest validates Person creation/update requests.
 *
 * Validates:
 * - email_plain: required, valid email format
 * - phone_plain: optional, string
 * - note_plain: optional, string
 */
class StorePersonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by middleware (auth:sanctum, SetTenant, permission)
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email_plain' => ['required', 'email'],
            'phone_plain' => ['nullable', 'string'],
            'note_plain' => ['nullable', 'string'],
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
            'email_plain.required' => 'Email address is required',
            'email_plain.email' => 'Email address must be valid',
        ];
    }
}
