<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api;

use App\Models\SecPalObject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for updating an object.
 */
class UpdateSecPalObjectRequest extends FormRequest
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
        /** @var SecPalObject $object */
        $object = $this->route('object');

        return [
            'object_number' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('objects', 'object_number')->ignore($object->id),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'string', 'max:500'],
            'gps_coordinates' => ['nullable', 'array'],
            'gps_coordinates.lat' => ['required_with:gps_coordinates', 'numeric', 'between:-90,90'],
            'gps_coordinates.lon' => ['required_with:gps_coordinates', 'numeric', 'between:-180,180'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
