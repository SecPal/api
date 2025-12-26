<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeResource transforms Employee models into API responses.
 *
 * Returns decrypted personal data for authorized users.
 * Authorization is enforced at controller level via policies.
 *
 * @mixin \App\Models\Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * Disable wrapping for single resources.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_number' => $this->employee_number,

            // Decrypted personal data (via accessors)
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'date_of_birth' => $this->date_of_birth,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'photo_path' => $this->photo_path,

            // Tax & Social Security (decrypted)
            'tax_id' => $this->tax_id,
            'social_security_number' => $this->social_security_number,

            // Employment Status
            'status' => $this->status,
            'hire_date' => $this->hire_date?->toDateString(),
            'contract_start_date' => $this->contract_start_date?->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'last_working_day' => $this->last_working_day?->toDateString(),

            // Contract Details
            'contract_type' => $this->contract_type,
            'weekly_hours' => $this->weekly_hours,
            'monthly_hours' => $this->monthly_hours,
            'hourly_rate' => $this->hourly_rate,

            // Health Insurance
            'health_insurance_type' => $this->health_insurance_type,
            'health_insurance_provider' => $this->health_insurance_provider,
            'health_insurance_number' => $this->health_insurance_number,

            // Legal Requirements (BewachV)
            'sachkunde_type' => $this->sachkunde_type,
            'sachkunde_certificate' => $this->sachkunde_certificate,
            'sachkunde_expiry' => $this->sachkunde_expiry?->toDateString(),

            // Work & Residence Permits
            'work_permit_type' => $this->work_permit_type,
            'work_permit_number' => $this->work_permit_number,
            'work_permit_expiry' => $this->work_permit_expiry?->toDateString(),
            'residence_permit_type' => $this->residence_permit_type,
            'residence_permit_number' => $this->residence_permit_number,
            'residence_permit_expiry' => $this->residence_permit_expiry?->toDateString(),

            // Criminal Record
            'criminal_record_status' => $this->criminal_record_status,
            'criminal_record_check_date' => $this->criminal_record_check_date?->toDateString(),

            // User Account
            'user_id' => $this->user_id,
            'user_account_active' => $this->user_account_active,
            'user_account_activated_at' => $this->user_account_activated_at?->toIso8601String(),
            'user_account_deactivated_at' => $this->user_account_deactivated_at?->toIso8601String(),

            // Onboarding
            'onboarding_completed' => $this->onboarding_completed,
            'onboarding_steps' => $this->onboarding_steps,
            'onboarding_started_at' => $this->onboarding_started_at?->toIso8601String(),
            'onboarding_completed_at' => $this->onboarding_completed_at?->toIso8601String(),

            // Organizational
            'organizational_unit_id' => $this->organizational_unit_id,
            'position' => $this->position,
            'management_level' => $this->management_level,

            // Relationships (optional, load when needed)
            'user' => new UserResource($this->whenLoaded('user')),
            'organizational_unit' => new OrganizationalUnitResource($this->whenLoaded('organizationalUnit')),
            'qualifications' => EmployeeQualificationResource::collection($this->whenLoaded('employeeQualifications')),
            'documents' => EmployeeDocumentResource::collection($this->whenLoaded('documents')),

            // Timestamps
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
