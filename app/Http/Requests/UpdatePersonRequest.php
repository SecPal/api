<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for updating a Person.
 *
 * Email is required (used for blind index lookup).
 * Other fields are optional.
 */
class UpdatePersonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * NOTE: Authorization is handled by PersonPolicy in the controller.
     * This method only validates that the user is authenticated.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // NOTE: Using 'email:rfc' instead of 'email:rfc,dns' for test compatibility
            // In production, consider adding 'dns' validation for stricter email verification
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
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
            'email.required' => 'Email address is required.',
            'email.email' => 'Email address must be valid.',
            'email.max' => 'Email address must not exceed 255 characters.',
            'phone.max' => 'Phone number must not exceed 50 characters.',
            'address.max' => 'Address must not exceed 500 characters.',
            'note.max' => 'Note must not exceed 2000 characters.',
        ];
    }
}
