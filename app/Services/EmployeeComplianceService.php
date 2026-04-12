<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;

class EmployeeComplianceService
{
    /**
     * @return Collection<int, array{type: string, label: string, expiry: string, status: string, days_until_expiry: int}>
     */
    public function alertDocuments(Employee $employee, ?string $status = null): Collection
    {
        return $employee->expiring_documents
            ->filter(function (array $document) use ($status): bool {
                if ($status === null) {
                    return in_array($document['status'], ['warning', 'critical', 'expired'], true);
                }

                return $document['status'] === $status;
            })
            ->values();
    }

    public function hasAlerts(Employee $employee, ?string $status = null): bool
    {
        if ($status === null) {
            return $this->alertDocuments($employee)->isNotEmpty();
        }

        return $this->highestAlertStatus($employee) === $status;
    }

    /**
     * @return Collection<int, array{type: string, label: string, expiry: string, status: string, days_until_expiry: int}>
     */
    public function blockingDocuments(Employee $employee): Collection
    {
        return $this->alertDocuments($employee)
            ->filter(fn (array $document): bool => in_array($document['status'], ['critical', 'expired'], true))
            ->map(fn (array $document): array => [
                'type' => $document['type'],
                'label' => $document['label'],
                'expiry' => $document['expiry'],
                'status' => (string) $document['status'],
                'days_until_expiry' => $document['days_until_expiry'],
            ])
            ->values();
    }

    public function highestAlertStatus(Employee $employee): ?string
    {
        /** @var string|null $status */
        $status = $this->alertDocuments($employee)
            ->sortByDesc(fn (array $document): int => $this->severity((string) $document['status']))
            ->pluck('status')
            ->first();

        return $status;
    }

    public function severity(string $status): int
    {
        return match ($status) {
            'expired' => 3,
            'critical' => 2,
            'warning' => 1,
            default => 0,
        };
    }
}
