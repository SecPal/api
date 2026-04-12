<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeBwrStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        return $employee !== null && ($this->user()?->can('update', $employee) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        return [
            'status' => ['required', 'string', Rule::in(['active', 'suspended', 'revoked'])],
            'bwr_id' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === 'active' && ($employee?->bwr_id === null || $employee->bwr_id === '')),
                'nullable',
                'string',
                'size:7',
                'regex:/^[0-9]{7}$/',
                Rule::unique('employees', 'bwr_id')->ignore($employee?->id),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
