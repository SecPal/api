<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use App\Models\Employee;
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
            'photo_path' => $this->photo_path,

            // BewachV § 16 Abs. 2 Nr. 1: BWR Registration Tracking
            'bwr_id' => $this->bwr_id, // 7-digit Bewacher-ID
            'bwr_status' => $this->bwr_status,
            'bwr_registered_at' => $this->bwr_registered_at?->toIso8601String(),
            'bwr_submission_date' => $this->bwr_submission_date?->toDateString(),
            'bwr_notes' => $this->bwr_notes,

            // BewachV § 16 Abs. 2 Nr. 2: Identity Data
            'gender' => $this->gender,
            'birth_name' => $this->birth_name, // Decrypted
            'previous_names' => $this->previous_names, // JSON array

            // BewachV § 16 Abs. 2 Nr. 3: Birth Place
            'birth_city' => $this->birth_city,
            'birth_country' => $this->birth_country, // ISO 3166-1 alpha-2
            'birth_state' => $this->birth_state,

            // BewachV § 16 Abs. 2 Nr. 4: Nationalities (supports dual citizenship)
            'nationalities' => $this->nationalities, // JSON array of ISO codes

            // BewachV § 16 Abs. 2 Nr. 5: Structured Current Address (decrypted)
            'address_street' => $this->address_street,
            'address_house_number' => $this->address_house_number,
            'address_postal_code' => $this->address_postal_code,
            'address_city' => $this->address_city,
            'address_supplement' => $this->address_supplement,
            'address_country' => $this->address_country, // ISO 3166-1 alpha-2
            'address_state' => $this->address_state,
            'structured_address' => $this->structured_address, // Computed property

            // BewachV § 16 Abs. 2 Nr. 6: Address History (Last 5 Years)
            'address_history' => $this->address_history, // JSON array

            // BewachV § 16 Abs. 2 Nr. 7: Intended Activities (§34a work types)
            'intended_activities' => $this->intended_activities, // JSON array

            // BewachV § 16 Abs. 2 Nr. 11: ID Document
            'id_document_type' => $this->id_document_type,
            'id_document_number' => $this->id_document_number, // Decrypted, sensitive!
            'id_document_expiry' => $this->id_document_expiry?->toDateString(),
            'id_document_copy_path' => $this->id_document_copy_path,
            'id_document_copy_deleted_at' => $this->id_document_copy_deleted_at?->toIso8601String(),

            // BewachV § 21: Retention Management
            'employment_end_date' => $this->employment_end_date,
            'retention_period_end' => $this->retention_period_end,

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

            // Legal Requirements (BewachV § 34a - Sachkunde)
            // Note: Sachkunde qualification NEVER expires - valid for life!
            'sachkunde_type' => $this->sachkunde_type,
            'sachkunde_certificate' => $this->sachkunde_certificate,
            'sachkunde_ihk_number' => $this->sachkunde_ihk_number,
            'sachkunde_exam_date' => $this->sachkunde_exam_date?->format('Y-m-d'),
            'sachkunde_issued_date' => $this->sachkunde_issued_date?->format('Y-m-d'),

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
            'onboarding_invitation' => [
                'status' => $this->onboarding_invitation_status ?? 'not_requested',
                'available' => $this->canReceiveOnboardingInvitation(),
                'eligible_statuses' => Employee::INVITABLE_STATUSES,
                'rule_message' => $this->canReceiveOnboardingInvitation()
                    ? __('Onboarding invitations can be requested while the employee remains in pre_contract status.')
                    : __('Onboarding invitations are only available while the employee is in pre_contract status.'),
                'requested_at' => $this->onboarding_invitation_requested_at?->toIso8601String(),
                'token_created_at' => $this->onboarding_invitation_token_created_at?->toIso8601String(),
                'mail_sent_at' => $this->onboarding_invitation_mail_sent_at?->toIso8601String(),
                'mail_failed_at' => $this->onboarding_invitation_mail_failed_at?->toIso8601String(),
                'failure_reason' => $this->onboarding_invitation_failure_reason,
            ],

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
