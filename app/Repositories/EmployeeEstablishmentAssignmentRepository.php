<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Employment\EmploymentPeriod;
use App\Models\Organization\EmployeeEstablishmentAssignment;
use App\Models\Organization\EstablishmentVersion;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EmployeeEstablishmentAssignmentRepository
{
    public function __construct(private EstablishmentRepository $establishmentVersions) {}

    public function lockEmployment(int $tenantId, string $employeeId, ?string $instant): ?EmploymentPeriod
    {
        return EmploymentPeriod::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereRaw('effective_range @> COALESCE(CAST(? AS timestamptz), statement_timestamp())', [$instant])
            ->lockForUpdate()
            ->first();
    }

    public function lockPredecessor(EmploymentPeriod $period, string $id, ?string $instant): ?EmployeeEstablishmentAssignment
    {
        return EmployeeEstablishmentAssignment::query()
            ->where('tenant_id', $period->tenant_id)
            ->where('employee_id', $period->employee_id)
            ->where('employment_period_id', $period->id)
            ->whereKey($id)
            ->whereRaw('valid_from < COALESCE(CAST(? AS timestamptz), statement_timestamp())', [$instant])
            ->whereRaw('effective_range @> COALESCE(CAST(? AS timestamptz), statement_timestamp())', [$instant])
            ->lockForUpdate()
            ->first();
    }

    public function create(
        EmploymentPeriod $period,
        string $establishmentId,
        ?DateTimeInterface $from,
        ?DateTimeInterface $until,
    ): EmployeeEstablishmentAssignment {
        return EmployeeEstablishmentAssignment::query()->create([
            'tenant_id' => $period->tenant_id,
            'employee_id' => $period->employee_id,
            'employment_period_id' => $period->id,
            'establishment_id' => $establishmentId,
            'valid_from' => $from,
            'valid_until' => $until,
        ]);
    }

    public function transfer(
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

    /** @return array{assignment: EmployeeEstablishmentAssignment, establishment: EstablishmentVersion}|null */
    public function at(int $tenantId, string $employeeId, DateTimeInterface $at): ?array
    {
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
