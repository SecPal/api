<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use App\Services\EmployeeComplianceService;
use Illuminate\Validation\Rule;

/**
 * IndexEmployeeComplianceAlertsRequest validates employee compliance alert filters.
 */
class IndexEmployeeComplianceAlertsRequest extends IndexEmployeeRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'compliance_status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(EmployeeComplianceService::ALERT_STATUSES),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $complianceStatusMessage = __('Compliance status filter must be one of: :statuses.', [
            'statuses' => implode(', ', EmployeeComplianceService::ALERT_STATUSES),
        ]);

        return [
            ...parent::messages(),
            'compliance_status.required' => $complianceStatusMessage,
            'compliance_status.string' => $complianceStatusMessage,
            'compliance_status.in' => $complianceStatusMessage,
        ];
    }
}
