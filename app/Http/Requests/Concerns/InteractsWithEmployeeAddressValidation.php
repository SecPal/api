<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Concerns;

use App\Models\Employee;
use Illuminate\Validation\Validator;

trait InteractsWithEmployeeAddressValidation
{
    /**
     * Rules for each address row under `addresses` (container rule is added per request).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function employeeAddressItemRules(): array
    {
        return [
            'addresses.*.street' => ['nullable', 'string', 'max:255'],
            'addresses.*.house_number' => ['nullable', 'string', 'max:10'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.city' => ['nullable', 'string', 'max:255'],
            'addresses.*.supplement' => ['nullable', 'string', 'max:255'],
            'addresses.*.country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'addresses.*.state' => ['nullable', 'string', 'max:100'],
            'addresses.*.resided_from' => ['nullable', 'date'],
            'addresses.*.resided_until' => ['nullable', 'date'],
        ];
    }

    protected function validateEmployeeAddressesPayload(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        /** @var array<int, mixed>|null $addresses */
        $addresses = $this->input('addresses');
        if ($addresses === null || ! is_array($addresses)) {
            return;
        }

        $currentRows = [];
        foreach ($addresses as $index => $row) {
            if (! is_array($row)) {
                $validator->errors()->add('addresses.'.$index, __('Each address entry must be an object.'));

                continue;
            }

            $untilRaw = $row['resided_until'] ?? null;
            $until = ($untilRaw === null || $untilRaw === '') ? null : (string) $untilRaw;
            if ($until === null) {
                $currentRows[] = $index;
            }

            $fromRaw = $row['resided_from'] ?? null;
            $from = ($fromRaw === null || $fromRaw === '') ? null : (string) $fromRaw;

            if ($from !== null && $until !== null) {
                try {
                    $fromDate = new \DateTimeImmutable($from);
                    $untilDate = new \DateTimeImmutable($until);
                    if ($untilDate < $fromDate) {
                        $validator->errors()->add(
                            'addresses.'.$index.'.resided_until',
                            __('End date must be on or after start date.'),
                        );
                    }
                } catch (\Throwable) {
                    // Individual date rules already failed earlier.
                }
            }

            if ($until !== null && ($from === null || $from === '')) {
                $validator->errors()->add(
                    'addresses.'.$index.'.resided_from',
                    __('A start date is required for past addresses.'),
                );
            }
        }

        if (count($currentRows) > 1) {
            $validator->errors()->add(
                'addresses',
                __('At most one address may be current (resided_until empty).'),
            );
        }

        if (! $this->employeeAddressesPayloadRequiresBwrFields()) {
            return;
        }

        if (count($currentRows) !== 1) {
            $validator->errors()->add(
                'addresses',
                __('For BWR submission, exactly one current address is required (resided_until empty).'),
            );

            return;
        }

        $idx = $currentRows[0];
        /** @var array<string, mixed> $current */
        $current = $addresses[$idx];
        foreach (
            [
                'street' => __('Street is required for BWR on the current address.'),
                'postal_code' => __('Postal code is required for BWR on the current address.'),
                'city' => __('City is required for BWR on the current address.'),
                'country' => __('Country is required for BWR on the current address.'),
            ] as $field => $message
        ) {
            $value = $current[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $validator->errors()->add('addresses.'.$idx.'.'.$field, $message);
            }
        }
    }

    private function employeeAddressesPayloadRequiresBwrFields(): bool
    {
        $status = $this->input('bwr_status');
        if (is_string($status) && in_array($status, ['pending', 'active'], true)) {
            return true;
        }

        $employee = $this->route('employee');

        return $employee instanceof Employee
            && is_string($employee->bwr_status)
            && in_array($employee->bwr_status, ['pending', 'active'], true);
    }
}
