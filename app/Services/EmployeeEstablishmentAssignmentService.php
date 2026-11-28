<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization\EmployeeEstablishmentAssignment;
use App\Models\Organization\EstablishmentVersion;
use App\Repositories\EmployeeEstablishmentAssignmentRepository;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Callers authorize both the Employee and Establishment scopes before each operation. */
final readonly class EmployeeEstablishmentAssignmentService
{
    public function __construct(
        private EstablishmentService $establishments,
        private EmployeeEstablishmentAssignmentRepository $assignments,
    ) {}

    /**
     * Bounds are absolute instants with microsecond precision and an exclusive
     * end. Resolve tenant civil time to an explicit offset before calling.
     * Start employment and its initial assignment in the same transaction.
     * Pass a null start with an expected predecessor for an immediate
     * transfer. PostgreSQL chooses the effective instant under the write locks.
     */
    public function schedule(
        int $tenantId,
        string $employeeId,
        string $establishmentId,
        ?DateTimeInterface $from,
        ?DateTimeInterface $until,
        ?string $replacesId = null,
    ): EmployeeEstablishmentAssignment {
        if (! Str::isUuid($employeeId) || ($from === null && $replacesId === null)
            || ($from !== null && $until !== null && $until <= $from)) {
            throw new DomainException('Invalid establishment assignment interval or employee.');
        }

        return DB::transaction(function () use ($tenantId, $employeeId, $establishmentId, $from, $until, $replacesId): EmployeeEstablishmentAssignment {
            $instant = $from?->format('Y-m-d H:i:s.uP');
            $period = $this->assignments->lockEmployment($tenantId, $employeeId, $instant);
            if ($period === null) {
                throw new DomainException('No employment covers the requested establishment assignment.');
            }

            $this->establishments->lockAssignable($tenantId, $establishmentId);
            if ($replacesId !== null) {
                $previous = Str::isUuid($replacesId) ? $this->assignments->lockPredecessor($period, $replacesId, $instant) : null;
                if ($previous === null || $previous->valid_until != $until) {
                    throw new DomainException('The expected establishment assignment has changed.');
                }

                return $this->assignments->transfer($previous, $establishmentId, $instant);
            }

            return $this->assignments->create($period, $establishmentId, $from, $until);
        });
    }

    /**
     * Resolve structural facts at the same instant for both the assignment
     * and the establishment version. Action authority remains with the caller.
     *
     * @return array{assignment: EmployeeEstablishmentAssignment, establishment: EstablishmentVersion}|null
     */
    public function at(int $tenantId, string $employeeId, DateTimeInterface $at): ?array
    {
        if (! Str::isUuid($employeeId)) {
            return null;
        }

        return $this->assignments->at($tenantId, $employeeId, $at);
    }
}
