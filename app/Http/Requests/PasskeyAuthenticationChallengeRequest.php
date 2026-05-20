<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyAuthenticationChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.prohibited' => 'Email-scoped public passkey challenges are no longer supported. Start passkey sign-in without an email address.',
        ];
    }
}
