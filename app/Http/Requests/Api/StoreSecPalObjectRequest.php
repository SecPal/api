<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for storing a new object.
 */
class StoreSecPalObjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by controller via policy
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
            'customer_id' => ['required', 'uuid', Rule::exists(Customer::class, 'id')],
            'object_number' => ['required', 'string', 'max:100', 'unique:objects,object_number'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'gps_coordinates' => ['nullable', 'array'],
            'gps_coordinates.lat' => ['required_with:gps_coordinates', 'numeric', 'between:-90,90'],
            'gps_coordinates.lon' => ['required_with:gps_coordinates', 'numeric', 'between:-180,180'],
            'metadata' => ['nullable', 'array'],
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
            'customer_id.exists' => 'The selected customer does not exist.',
            'object_number.unique' => 'This object number is already in use.',
        ];
    }
}
