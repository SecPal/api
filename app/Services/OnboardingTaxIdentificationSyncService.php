<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;

class OnboardingTaxIdentificationSyncService
{
    public function syncFromApprovedSubmission(OnboardingFormSubmission $submission): void
    {
        if ($submission->status !== 'approved') {
            return;
        }

        $template = $submission->formTemplate;
        if (
            ! $template instanceof OnboardingFormTemplate
            || $template->template_key !== 'tax_identification_number'
            || ! $template->is_system_template
            || $template->tenant_id !== null
        ) {
            return;
        }

        $formData = $submission->form_data;
        if (! is_array($formData)) {
            return;
        }

        /** @var Employee|null $employee */
        $employee = $submission->employee;
        if (! $employee instanceof Employee) {
            return;
        }

        $updates = [];

        if (isset($formData['tax_id']) && is_string($formData['tax_id'])) {
            $taxId = trim($formData['tax_id']);
            if ($taxId !== '') {
                $updates['tax_id'] = $taxId;
            }
        }

        if (isset($formData['social_security_number']) && is_string($formData['social_security_number'])) {
            $socialSecurityNumber = trim($formData['social_security_number']);
            if ($socialSecurityNumber !== '') {
                $updates['social_security_number'] = $socialSecurityNumber;
            }
        }

        if ($updates !== []) {
            $employee->forceFill($updates)->save();
        }
    }
}
