<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * IndexEmployeeRequest validates employee list filters.
 */
class IndexEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var int|string|null $tenantId */
        $tenantId = $this->input('tenant_id');

        return [
            'status' => ['nullable', Rule::in([
                Employee::STATUS_APPLICANT,
                Employee::STATUS_PRE_CONTRACT,
                Employee::STATUS_ACTIVE,
                Employee::STATUS_ON_LEAVE,
                Employee::STATUS_TERMINATED,
            ])],
            'organizational_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organizational_units', 'id')->where('tenant_id', $tenantId),
            ],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
