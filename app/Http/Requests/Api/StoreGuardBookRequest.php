<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api;

use App\Models\ObjectArea;
use App\Models\SecPalObject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for storing a new guard book.
 */
class StoreGuardBookRequest extends FormRequest
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
            'object_id' => ['nullable', 'uuid', Rule::exists(SecPalObject::class, 'id')],
            'object_area_id' => ['nullable', 'uuid', Rule::exists(ObjectArea::class, 'id')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $hasObject = ! is_null($this->input('object_id'));
            $hasArea = ! is_null($this->input('object_area_id'));

            if ($hasObject && $hasArea) {
                $validator->errors()->add(
                    'object_id',
                    'A guard book must belong to EITHER an object OR an object area, not both.'
                );
            }

            if (! $hasObject && ! $hasArea) {
                $validator->errors()->add(
                    'object_id',
                    'A guard book must belong to either an object or an object area.'
                );
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'object_id.exists' => 'The selected object does not exist.',
            'object_area_id.exists' => 'The selected object area does not exist.',
        ];
    }
}
