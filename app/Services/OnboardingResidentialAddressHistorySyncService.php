<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;

class OnboardingResidentialAddressHistorySyncService
{
    public function syncFromApprovedSubmission(OnboardingFormSubmission $submission): void
    {
        if ($submission->status !== 'approved') {
            return;
        }

        $template = $submission->formTemplate;
        if (
            ! $template instanceof OnboardingFormTemplate
            || $template->template_key !== 'residential_address_history'
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

        $currentAddress = $this->normalizeAddressRow(
            $formData['current_address'] ?? null,
            current: true,
        );

        if ($currentAddress === null) {
            return;
        }

        $rows = [];

        $previousAddresses = $formData['previous_addresses'] ?? [];
        if (is_array($previousAddresses)) {
            foreach ($previousAddresses as $previousAddress) {
                $normalized = $this->normalizeAddressRow($previousAddress, current: false);
                if ($normalized !== null) {
                    $rows[] = $normalized;
                }
            }
        }

        $rows[] = $currentAddress;

        $employee->addresses()->delete();

        foreach ($rows as $row) {
            EmployeeAddress::query()->create([
                'employee_id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'street' => $row['street'],
                'house_number' => $row['house_number'],
                'postal_code' => $row['postal_code'],
                'city' => $row['city'],
                'supplement' => $row['supplement'],
                'country' => $row['country'],
                'resided_from' => $row['resided_from'],
                'resided_until' => $row['resided_until'],
            ]);
        }
    }

    /**
     * @return array{
     *     street: ?string,
     *     house_number: ?string,
     *     postal_code: ?string,
     *     city: ?string,
     *     supplement: ?string,
     *     country: ?string,
     *     resided_from: ?string,
     *     resided_until: ?string
     * }|null
     */
    private function normalizeAddressRow(mixed $value, bool $current): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return [
            'street' => $this->nullableString($value['street'] ?? null),
            'house_number' => $this->nullableString($value['house_number'] ?? null),
            'postal_code' => $this->nullableString($value['postal_code'] ?? null),
            'city' => $this->nullableString($value['city'] ?? null),
            'supplement' => $this->nullableString($value['supplement'] ?? null),
            'country' => $this->nullableString($value['country'] ?? null),
            'resided_from' => $this->nullableString($value['resided_from'] ?? null),
            'resided_until' => $current ? null : $this->nullableString($value['resided_until'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
