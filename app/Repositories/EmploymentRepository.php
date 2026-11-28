<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Employee;
use App\Models\Employment\EmploymentPeriod;
use App\Models\Employment\EmploymentTermsVersion;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use SensitiveParameter;

final class EmploymentRepository
{
    public function lockEmployee(int $tenantId, string $employeeId): void
    {
        $this->requireUuid($employeeId, Employee::class);
        Employee::query()->where('tenant_id', $tenantId)->whereKey($employeeId)->lockForUpdate()->firstOrFail(['id']);
    }

    public function lockPeriod(int $tenantId, string $periodId): EmploymentPeriod
    {
        $this->requireUuid($periodId, EmploymentPeriod::class);

        return EmploymentPeriod::query()->where('tenant_id', $tenantId)->whereKey($periodId)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function createPeriod(array $attributes): EmploymentPeriod
    {
        return EmploymentPeriod::query()->create($attributes)->refresh();
    }

    public function end(EmploymentPeriod $period, DateTimeInterface $until, string $evidenceId): EmploymentPeriod
    {
        $period->update(['valid_until' => $until, 'end_evidence_id' => $evidenceId]);

        return $period->refresh();
    }

    /** @param array<string, mixed> $conditions */
    public function appendTerms(
        string $id,
        EmploymentPeriod $period,
        DateTimeInterface $from,
        string $evidenceId,
        #[SensitiveParameter] array $conditions,
        ?string $replacesId,
    ): EmploymentTermsVersion {
        $version = new EmploymentTermsVersion;
        $version->id = $id;
        $version->fill([
            'tenant_id' => $period->tenant_id,
            'employee_id' => $period->employee_id,
            'employment_period_id' => $period->id,
            'evidence_id' => $evidenceId,
            'replaces_id' => $replacesId,
            'effective_from' => $from,
            'conditions_plain' => $conditions,
        ])->save();

        return $version->refresh();
    }

    public function termsAt(
        int $tenantId,
        string $periodId,
        DateTimeInterface $effectiveAt,
        ?DateTimeInterface $knownAt,
    ): ?EmploymentTermsVersion {
        if (! Str::isUuid($periodId)) {
            return null;
        }

        $effective = $effectiveAt->format('Y-m-d H:i:s.uP');
        $query = EmploymentTermsVersion::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_period_id', $periodId)
            ->where('effective_from', '<=', $effective)
            ->whereExists(static function (Builder $period) use ($effective): void {
                $period->from('employment_periods')
                    ->whereColumn('employment_periods.id', 'employment_terms_versions.employment_period_id')
                    ->where('employment_periods.valid_from', '<=', $effective)
                    ->where(static function (Builder $end) use ($effective): void {
                        $end->whereNull('employment_periods.valid_until')
                            ->orWhere('employment_periods.valid_until', '>', $effective);
                    });
            });

        if ($knownAt !== null) {
            $query->where('recorded_at', '<=', $knownAt->format('Y-m-d H:i:s.uP'));
        }

        return $query->orderByDesc('effective_from')->orderByDesc('recorded_at')->first();
    }

    public function version(int $tenantId, string $periodId, string $versionId): EmploymentTermsVersion
    {
        $this->requireUuid($periodId, EmploymentPeriod::class);
        $this->requireUuid($versionId, EmploymentTermsVersion::class);

        return EmploymentTermsVersion::query()->where('tenant_id', $tenantId)
            ->where('employment_period_id', $periodId)->whereKey($versionId)->firstOrFail();
    }

    /** @return array{current: ?EmploymentPeriod, future: ?EmploymentPeriod} */
    public function basis(int $tenantId, string $employeeId, DateTimeInterface $at): array
    {
        if (! Str::isUuid($employeeId)) {
            return ['current' => null, 'future' => null];
        }

        $instant = $at->format('Y-m-d H:i:s.uP');
        $periods = EmploymentPeriod::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where(static function ($query) use ($instant): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', $instant);
            })
            ->whereExists(static function (Builder $employee): void {
                $employee->from('employees')->whereColumn('employees.id', 'employment_periods.employee_id')
                    ->whereNull('employees.deleted_at');
            })
            ->whereExists(static function (Builder $version): void {
                $version->from('employment_terms_versions')
                    ->whereColumn('employment_terms_versions.id', 'employment_periods.initial_terms_id')
                    ->whereColumn('employment_terms_versions.employment_period_id', 'employment_periods.id')
                    ->whereColumn('employment_terms_versions.effective_from', 'employment_periods.valid_from');
            })
            ->orderBy('valid_from')
            ->limit(2)
            ->get();
        $first = $periods->first();
        if ($first !== null && $first->valid_from <= $at) {
            return ['current' => $first, 'future' => $periods->get(1)];
        }

        return ['current' => null, 'future' => $first];
    }

    /** @param class-string<Model> $model */
    private function requireUuid(string $value, string $model): void
    {
        if (! Str::isUuid($value)) {
            throw (new ModelNotFoundException)->setModel($model);
        }
    }
}
