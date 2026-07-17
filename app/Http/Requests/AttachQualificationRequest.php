<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\Qualification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * AttachQualificationRequest validates requests to attach a qualification to an employee.
 *
 * Creates an EmployeeQualification pivot record with certificate details.
 */
class AttachQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && ($this->user()?->can('create', [EmployeeQualification::class, $employee]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var int|null $tenantId */
        $tenantId = $this->integer('tenant_id') ?: $this->user()?->tenant_id;

        return [
            'qualification_id' => [
                'required',
                'uuid',
                Rule::exists(Qualification::class, 'id')->where(function (\Illuminate\Database\Query\Builder $query) use ($tenantId): void {
                    $query->whereNull('deleted_at');

                    if ($tenantId === null) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->where(function (\Illuminate\Database\Query\Builder $qualificationQuery) use ($tenantId): void {
                        $qualificationQuery->where('tenant_id', $tenantId)
                            ->orWhere(function (\Illuminate\Database\Query\Builder $globalQualificationQuery): void {
                                $globalQualificationQuery->whereNull('tenant_id')
                                    ->where('is_system_qualification', true);
                            });
                    });
                }),
            ],
            'obtained_date' => ['required', 'date', 'before_or_equal:today'],
            'expiry_date' => ['nullable', 'date', 'after:obtained_date'],
            'certificate_number' => ['nullable', 'string', 'max:255'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in(['valid', 'expiring_soon', 'expired'])],
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
            'qualification_id.required' => __('Qualification is required'),
            'qualification_id.exists' => __('Selected qualification does not exist'),
            'obtained_date.required' => __('Obtained date is required'),
            'obtained_date.before_or_equal' => __('Obtained date cannot be in the future'),
            'expiry_date.after' => __('Expiry date must be after obtained date'),
        ];
    }
}
