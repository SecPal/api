<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Employment\EmploymentPeriod;
use App\Models\Organization\EmployeeEstablishmentAssignment;
use App\Models\Organization\EstablishmentVersion;
use App\Repositories\EstablishmentRepository;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Callers authorize both the Employee and Establishment scopes before each operation. */
final readonly class EmployeeEstablishmentAssignmentService
{
    public function __construct(
        private EstablishmentService $establishments,
        private EstablishmentRepository $establishmentVersions,
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
            $period = EmploymentPeriod::query()
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)
                ->whereRaw('effective_range @> COALESCE(CAST(? AS timestamptz), statement_timestamp())', [$instant])
                ->lockForUpdate()
                ->first();
            if ($period === null) {
                throw new DomainException('No employment covers the requested establishment assignment.');
            }

            $this->establishments->lockAssignable($tenantId, $establishmentId);
            if ($replacesId !== null) {
                $previous = Str::isUuid($replacesId) ? EmployeeEstablishmentAssignment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->where('employment_period_id', $period->id)
                    ->whereKey($replacesId)
                    ->whereRaw('valid_from < COALESCE(CAST(? AS timestamptz), statement_timestamp())', [$instant])
                    ->whereRaw('effective_range @> COALESCE(CAST(? AS timestamptz), statement_timestamp())', [$instant])
                    ->lockForUpdate()
                    ->first() : null;
                if ($previous === null || $previous->valid_until != $until) {
                    throw new DomainException('The expected establishment assignment has changed.');
                }

                return $this->transfer($previous, $establishmentId, $instant);
            }

            return EmployeeEstablishmentAssignment::query()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'employment_period_id' => $period->id,
                'establishment_id' => $establishmentId,
                'valid_from' => $from,
                'valid_until' => $until,
            ]);
        });
    }

    private function transfer(
        EmployeeEstablishmentAssignment $previous,
        string $establishmentId,
        ?string $instant,
    ): EmployeeEstablishmentAssignment {
        $id = (string) Str::uuid7();

        // The trigger resolves the immediate marker to the locked write time.
        // Both halves must be visible when immediate temporal constraints fire.
        DB::statement(<<<'SQL'
            WITH shortened AS (
                UPDATE employee_establishment_assignments
                SET valid_until = COALESCE(CAST(? AS timestamptz), '-infinity'::timestamptz)
                WHERE tenant_id = ? AND id = ?
                RETURNING tenant_id, employee_id, employment_period_id, valid_until
            )
            INSERT INTO employee_establishment_assignments
                (id, tenant_id, employee_id, employment_period_id, establishment_id, valid_from, valid_until)
            SELECT CAST(? AS uuid), tenant_id, employee_id, employment_period_id,
                   CAST(? AS uuid), valid_until, CAST(? AS timestamptz)
            FROM shortened
            SQL, [
            $instant, $previous->tenant_id, $previous->id, $id, $establishmentId,
            $previous->valid_until?->format('Y-m-d H:i:s.uP'),
        ]);

        return EmployeeEstablishmentAssignment::query()->where('tenant_id', $previous->tenant_id)->findOrFail($id);
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

        // Choose both identities in one PostgreSQL snapshot. The chosen
        // version is immutable and can be loaded by exact ID after that read.
        $version = $this->establishmentVersions->latestQuery($tenantId, $at)
            ->whereColumn('establishment_versions.establishment_id', 'employee_establishment_assignments.establishment_id')
            ->select('id');
        $assignment = EmployeeEstablishmentAssignment::query()->select('*')
            ->selectSub($version, 'resolved_version_id')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereRaw('effective_range @> CAST(? AS timestamptz)', [$at->format('Y-m-d H:i:s.uP')])
            ->first();
        if ($assignment === null || $assignment->resolved_version_id === null) {
            return null;
        }

        $establishment = $this->establishmentVersions->exactVersion($tenantId, $assignment->establishment_id, $assignment->resolved_version_id);
        if ($establishment === null || $establishment->is_closed) {
            return null;
        }

        return ['assignment' => $assignment, 'establishment' => $establishment];
    }
}
