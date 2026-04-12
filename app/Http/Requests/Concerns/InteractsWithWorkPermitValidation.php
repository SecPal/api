<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Concerns;

use App\Models\Employee;

trait InteractsWithWorkPermitValidation
{
    private function routeEmployee(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }

    private function mergedEmployeeValue(string $field): mixed
    {
        if ($this->exists($field)) {
            return $this->input($field);
        }

        return $this->routeEmployee()?->getAttribute($field);
    }

    /**
     * @return list<string>
     */
    private function mergedNationalities(): array
    {
        $nationalities = $this->mergedEmployeeValue('nationalities');
        if (! is_array($nationalities)) {
            return [];
        }

        return array_values(array_filter($nationalities, static fn (mixed $country): bool => is_string($country) && $country !== ''));
    }

    private function mergedWorkPermitType(): ?string
    {
        $type = $this->mergedEmployeeValue('work_permit_type');

        return is_string($type) && $type !== '' ? $type : null;
    }

    private function requiresWorkPermitForCurrentPayload(): bool
    {
        foreach ($this->mergedNationalities() as $country) {
            if (! in_array(strtoupper($country), Employee::NO_WORK_PERMIT_COUNTRIES, true)) {
                return true;
            }
        }

        return false;
    }

    private function requiresWorkPermitDetailsForCurrentPayload(): bool
    {
        $type = $this->mergedWorkPermitType();

        return $this->requiresWorkPermitForCurrentPayload()
            && is_string($type)
            && $type !== Employee::WORK_PERMIT_TYPE_NONE;
    }

    private function requiresWorkPermitExpiryForCurrentPayload(): bool
    {
        $type = $this->mergedWorkPermitType();

        return is_string($type)
            && in_array($type, Employee::WORK_PERMIT_TYPES_REQUIRING_EXPIRY, true)
            && $this->requiresWorkPermitForCurrentPayload();
    }
}
