<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Support\AddressHistoryLookback;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class BwrReportingReadiness
{
    /** @return list<string> */
    public function errors(Employee $employee): array
    {
        $employee->loadMissing('addresses');

        $errorCodes = [];

        foreach ($this->requiredFieldValues($employee) as $field => $value) {
            if ($this->isMissing($value)) {
                $errorCodes[] = $field;
            }
        }

        if ($employee->id_document_expiry !== null && $employee->id_document_expiry->lt(today())) {
            $errorCodes[] = 'id_document_expiry_expired';
        }

        if ($employee->requiresWorkPermit() && ! $employee->hasValidWorkAuthorization()) {
            $errorCodes[] = 'valid_work_authorization';
        }

        $this->validateAddressHistory($employee, $errorCodes);

        return $errorCodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredFieldValues(Employee $employee): array
    {
        return [
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'date_of_birth' => $employee->date_of_birth,
            'gender' => $employee->gender,
            'birth_city' => $employee->birth_city,
            'birth_country' => $employee->birth_country,
            'nationalities' => $employee->nationalities,
            'intended_activities' => $employee->intended_activities,
            'id_document_type' => $employee->id_document_type,
            'id_document_number' => $employee->id_document_number,
            'id_document_expiry' => $employee->id_document_expiry,
            'sachkunde_type' => $employee->sachkunde_type,
            'sachkunde_certificate' => $employee->sachkunde_certificate,
        ];
    }

    /**
     * @param  list<string>  $errorCodes
     */
    private function validateAddressHistory(Employee $employee, array &$errorCodes): void
    {
        /** @var Collection<int, EmployeeAddress> $addresses */
        $addresses = $employee->addresses;

        $current = $addresses->filter(fn (EmployeeAddress $a): bool => $a->resided_until === null);
        if ($current->count() === 0) {
            $errorCodes[] = 'current_address_missing';

            return;
        }

        if ($current->count() > 1) {
            $errorCodes[] = 'current_address_ambiguous';

            return;
        }

        /** @var EmployeeAddress $currentRow */
        $currentRow = $current->firstOrFail();

        foreach (
            [
                'street' => 'address_street',
                'house_number' => 'address_house_number',
                'postal_code' => 'address_postal_code',
                'city' => 'address_city',
                'country' => 'address_country',
            ] as $attr => $messageKey
        ) {
            if ($this->isMissing($currentRow->getAttribute($attr))) {
                $errorCodes[] = $messageKey;
            }
        }

        $past = $addresses->filter(fn (EmployeeAddress $a): bool => $a->resided_until !== null);
        if ($currentRow->resided_from === null && $past->isNotEmpty()) {
            $errorCodes[] = 'current_address_resided_from_required';

            return;
        }

        foreach ($past as $row) {
            if ($row->resided_from === null || $row->resided_until === null) {
                $errorCodes[] = 'address_history_incomplete';

                return;
            }
        }

        $windowStart = now()->startOfDay()->copy()->subYears(AddressHistoryLookback::YEARS);
        $windowEnd = now()->startOfDay();

        /** @var array<int, array{start: Carbon, end: Carbon}> $segments */
        $segments = [];

        foreach ($addresses as $addr) {
            if ($addr->resided_until === null) {
                $from = $addr->resided_from?->copy()->startOfDay() ?? $windowStart->copy();
                $segments[] = [
                    'start' => $from,
                    'end' => $windowEnd->copy(),
                ];
            } else {
                $segments[] = [
                    'start' => $addr->resided_from?->copy()->startOfDay() ?? $windowStart->copy(),
                    'end' => $addr->resided_until->copy()->startOfDay(),
                ];
            }
        }

        usort($segments, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        // Discard segments that end entirely before the window starts; they do not
        // contribute to window coverage and would produce false-positive gaps.
        $segments = array_values(
            array_filter($segments, fn (array $seg): bool => $seg['end']->gte($windowStart)),
        );

        $mergedEnd = null;
        foreach ($segments as $seg) {
            if ($mergedEnd === null) {
                if ($seg['start']->gt($windowStart)) {
                    $errorCodes[] = 'address_history_incomplete';

                    return;
                }
                $mergedEnd = $seg['end']->copy();

                continue;
            }

            if ($seg['start']->lte($mergedEnd)) {
                $errorCodes[] = 'address_history_overlap';

                return;
            }

            if ($seg['start']->gt($mergedEnd->copy()->addDay())) {
                $errorCodes[] = 'address_history_gap';

                return;
            }

            if ($seg['end']->gt($mergedEnd)) {
                $mergedEnd = $seg['end']->copy();
            }
        }

        if ($mergedEnd === null || $mergedEnd->lt($windowEnd)) {
            $errorCodes[] = 'address_history_incomplete';
        }
    }

    private function isMissing(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        if ($value instanceof \BackedEnum) {
            return false;
        }

        if ($value instanceof \DateTimeInterface) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null;
    }
}
