<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmployeeAddress;
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
        $canReadSensitiveIdentifiers = $request->user()?->can('employees.read_sensitive') ?? false;
        $canReadSalary = $request->user()?->can('employees.read_salary') ?? false;

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
            'bwr_registered_at' => \App\Support\ApiTimestamp::nullable($this->bwr_registered_at),
            'bwr_submission_date' => $this->bwr_submission_date?->toDateString(),
            'bwr_notes' => $this->bwr_notes,

            // BewachV § 16 Abs. 2 Nr. 2: Identity Data
            'gender' => $this->gender,
            'birth_name' => $this->birth_name, // Decrypted
            'previous_names' => $this->previous_names, // JSON array

            // BewachV § 16 Abs. 2 Nr. 3: Birth Place
            'birth_city' => $this->birth_city,
            'birth_country' => $this->birth_country, // ISO 3166-1 alpha-2 Geburtsland

            // BewachV § 16 Abs. 2 Nr. 4: Nationalities (supports dual citizenship)
            'nationalities' => $this->nationalities, // JSON array of ISO codes

            // BewachV § 16: Residential addresses (relation)
            'addresses' => EmployeeAddressResource::collection($this->whenLoaded('addresses')),
            'current_address' => $this->when($this->relationLoaded('addresses'), function (): mixed {
                /** @var EmployeeAddress|null $current */
                $current = $this->addresses->first(fn (EmployeeAddress $a): bool => $a->resided_until === null);

                return $current instanceof EmployeeAddress ? new EmployeeAddressResource($current) : null;
            }),
            'structured_address' => $this->structured_address,

            // Additional contact data
            'emergency_contacts' => $this->emergency_contacts, // JSON array

            // BewachV § 16 Abs. 2 Nr. 7: Intended Activities (§34a work types)
            'intended_activities' => $this->intended_activities, // JSON array

            // BewachV § 16 Abs. 2 Nr. 11: ID Document
            'id_document_type' => $this->id_document_type,
            'id_document_number' => $this->when($canReadSensitiveIdentifiers, fn () => $this->id_document_number),
            'id_document_expiry' => $this->id_document_expiry?->toDateString(),
            'id_document_copy_path' => $this->id_document_copy_path,
            'id_document_copy_deleted_at' => \App\Support\ApiTimestamp::nullable($this->id_document_copy_deleted_at),

            // BewachV § 21: Retention Management
            'employment_end_date' => $this->employment_end_date,
            'retention_period_end' => $this->retention_period_end,

            // Tax & Social Security (decrypted)
            'tax_id' => $this->when($canReadSensitiveIdentifiers, fn () => $this->tax_id),
            'social_security_number' => $this->when($canReadSensitiveIdentifiers, fn () => $this->social_security_number),

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
            'hourly_rate' => $this->when($canReadSalary, fn () => $this->hourly_rate),

            // Health Insurance
            'health_insurance_type' => $this->health_insurance_type,
            'health_insurance_provider' => $this->health_insurance_provider,
            'health_insurance_number' => $this->when($canReadSensitiveIdentifiers, fn () => $this->health_insurance_number),

            // Legal Requirements (BewachV § 34a - Sachkunde)
            // Note: Sachkunde qualification NEVER expires - valid for life!
            'sachkunde_type' => $this->sachkunde_type,
            'sachkunde_certificate' => $this->sachkunde_certificate,
            'sachkunde_ihk_number' => $this->when($canReadSensitiveIdentifiers, fn () => $this->sachkunde_ihk_number),
            'sachkunde_exam_date' => $this->sachkunde_exam_date?->format('Y-m-d'),
            'sachkunde_issued_date' => $this->sachkunde_issued_date?->format('Y-m-d'),

            // Work & Residence Permits
            'work_permit_type' => $this->work_permit_type,
            'work_permit_number' => $this->when($canReadSensitiveIdentifiers, fn () => $this->work_permit_number),
            'work_permit_expiry' => $this->work_permit_expiry?->toDateString(),
            'work_permit_copy_path' => $this->work_permit_copy_path,
            'work_permit_issued_by' => $this->work_permit_issued_by,
            'work_permit_copy_deleted_at' => \App\Support\ApiTimestamp::nullable($this->work_permit_copy_deleted_at),
            'firearms_license_number' => $this->when($canReadSensitiveIdentifiers, fn () => $this->firearms_license_number),
            'firearms_license_expiry' => $this->firearms_license_expiry?->toDateString(),
            'firearms_license_issued_by' => $this->firearms_license_issued_by,
            'first_aid_cert_number' => $this->when($canReadSensitiveIdentifiers, fn () => $this->first_aid_cert_number),
            'first_aid_cert_date' => $this->first_aid_cert_date?->toDateString(),
            'first_aid_cert_expiry' => $this->first_aid_cert_expiry?->toDateString(),
            'fire_safety_cert_date' => $this->fire_safety_cert_date?->toDateString(),
            'fire_safety_cert_expiry' => $this->fire_safety_cert_expiry?->toDateString(),
            'evacuation_cert_date' => $this->evacuation_cert_date?->toDateString(),
            'evacuation_cert_expiry' => $this->evacuation_cert_expiry?->toDateString(),
            'additional_certifications' => collect($this->additional_certifications ?? [])
                ->map(function (array $cert) use ($canReadSensitiveIdentifiers): array {
                    if (! $canReadSensitiveIdentifiers) {
                        unset($cert['number']);
                    }

                    return $cert;
                })
                ->all(),
            'residence_permit_type' => $this->residence_permit_type,
            'residence_permit_number' => $this->when($canReadSensitiveIdentifiers, fn () => $this->residence_permit_number),
            'residence_permit_expiry' => $this->residence_permit_expiry?->toDateString(),
            'requires_work_permit' => $this->requiresWorkPermit(),
            'has_valid_work_authorization' => $this->hasValidWorkAuthorization(),
            'expiring_documents' => $this->expiring_documents->all(),

            // Criminal Record
            'criminal_record_status' => $this->criminal_record_status,
            'criminal_record_check_date' => $this->criminal_record_check_date?->toDateString(),

            // User Account
            'user_id' => $this->user_id,
            'user_account_active' => $this->user_account_active,
            'user_account_activated_at' => \App\Support\ApiTimestamp::nullable($this->user_account_activated_at),
            'user_account_deactivated_at' => \App\Support\ApiTimestamp::nullable($this->user_account_deactivated_at),

            // Onboarding
            'onboarding_completed' => $this->onboarding_completed,
            'onboarding_steps' => $this->onboarding_steps,
            'onboarding_started_at' => \App\Support\ApiTimestamp::nullable($this->onboarding_started_at),
            'onboarding_completed_at' => \App\Support\ApiTimestamp::nullable($this->onboarding_completed_at),
            'onboarding_workflow' => [
                'status' => $this->resolveOnboardingWorkflowStatus(),
            ],
            'onboarding_invitation' => [
                'status' => $this->onboarding_invitation_status ?? 'not_requested',
                'available' => $this->canReceiveOnboardingInvitation(),
                'eligible_statuses' => Employee::INVITABLE_STATUSES,
                'rule_message' => $this->canReceiveOnboardingInvitation()
                    ? 'Onboarding invitations can be requested while the employee remains in pre_contract status.'
                    : 'Onboarding invitations are only available while the employee is in pre_contract status.',
                'requested_at' => \App\Support\ApiTimestamp::nullable($this->onboarding_invitation_requested_at),
                'token_created_at' => \App\Support\ApiTimestamp::nullable($this->onboarding_invitation_token_created_at),
                'mail_sent_at' => \App\Support\ApiTimestamp::nullable($this->onboarding_invitation_mail_sent_at),
                'mail_failed_at' => \App\Support\ApiTimestamp::nullable($this->onboarding_invitation_mail_failed_at),
                'failure_reason' => $this->onboarding_invitation_failure_reason,
            ],

            // Organizational
            'organizational_unit_id' => $this->organizational_unit_id,
            'position' => $this->position,
            'management_level' => $this->management_level,

            // Relationships (optional, load when needed)
            'user' => $this->whenLoaded('user', fn (): ?UserResource => $this->user === null ? null : new UserResource($this->user)),
            'organizational_unit' => $this->whenLoaded('organizationalUnit', fn (): ?OrganizationalUnitResource => $this->organizationalUnit === null ? null : new OrganizationalUnitResource($this->organizationalUnit)),
            'qualifications' => EmployeeQualificationResource::collection($this->whenLoaded('employeeQualifications')),
            'documents' => EmployeeDocumentResource::collection($this->whenLoaded('documents')),

            // Timestamps
            'created_at' => \App\Support\ApiTimestamp::format($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->updated_at),
            'deleted_at' => \App\Support\ApiTimestamp::nullable($this->deleted_at),
        ];
    }
}
