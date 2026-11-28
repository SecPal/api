<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\EmploymentTermsWriteException;
use App\Exceptions\TenantKeyMaintenanceException;
use App\Models\Employment\EmploymentPeriod;
use App\Models\Employment\EmploymentPeriodEnd;
use App\Models\Employment\EmploymentTermsVersion;
use App\Repositories\EmploymentRepository;
use Closure;
use DateTimeInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PDOException;
use SensitiveParameter;
use Throwable;

/** Employment persistence for callers that have already resolved and authorized the tenant and evidence. */
final readonly class EmploymentService
{
    public function __construct(
        private EmploymentRepository $employment,
        private TenantKekRegistry $keys,
    ) {}

    /** @param array<string, mixed> $conditions */
    public function start(
        int $tenantId,
        string $employeeId,
        DateTimeInterface $from,
        ?DateTimeInterface $until,
        string $evidenceId,
        #[SensitiveParameter] array $conditions,
    ): EmploymentPeriod {
        return $this->writeConditions($evidenceId, $conditions, null, function (#[SensitiveParameter] array $conditions) use ($tenantId, $employeeId, $from, $until, $evidenceId): EmploymentPeriod {
            $this->employment->lockEmployee($tenantId, $employeeId);
            $initialTermsId = (string) Str::uuid7();
            $period = $this->employment->createPeriod([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'valid_from' => $from,
                'valid_until' => $until,
                'end_evidence_id' => $until === null ? null : $evidenceId,
                'initial_terms_id' => $initialTermsId,
            ]);
            $this->employment->appendTerms($initialTermsId, $period, $from, $evidenceId, $conditions, null);

            return $period;
        });
    }

    /** @param array<string, mixed> $conditions */
    public function recordTerms(
        int $tenantId,
        string $periodId,
        DateTimeInterface $from,
        string $evidenceId,
        #[SensitiveParameter] array $conditions,
        ?string $replacesId = null,
    ): EmploymentTermsVersion {
        return $this->writeConditions($evidenceId, $conditions, $replacesId, function (#[SensitiveParameter] array $conditions) use ($tenantId, $periodId, $from, $evidenceId, $replacesId): EmploymentTermsVersion {
            $period = $this->employment->lockPeriod($tenantId, $periodId);

            return $this->employment->appendTerms((string) Str::uuid7(), $period, $from, $evidenceId, $conditions, $replacesId);
        });
    }

    /** End a period using the caller's separately verified evidence. */
    public function end(int $tenantId, string $periodId, DateTimeInterface $until, string $evidenceId): EmploymentPeriod
    {
        Validator::make(['evidence_id' => $evidenceId], ['evidence_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($tenantId, $periodId, $until, $evidenceId): EmploymentPeriod {
            $period = $this->employment->lockPeriod($tenantId, $periodId);

            return $this->employment->end($period, $until, $evidenceId);
        });
    }

    /** Resolve applicable terms. Pin the returned version ID to replay prior decisions. */
    public function termsAt(
        int $tenantId,
        string $periodId,
        DateTimeInterface $at,
    ): ?EmploymentTermsVersion {
        return $this->employment->termsAt($tenantId, $periodId, $at);
    }

    /** Retrieve an exact version previously cited by a calculation or personnel record. */
    public function version(int $tenantId, string $periodId, string $versionId): EmploymentTermsVersion
    {
        return $this->employment->version($tenantId, $periodId, $versionId);
    }

    /** @return Collection<int, EmploymentPeriodEnd> */
    public function endHistory(int $tenantId, string $periodId): Collection
    {
        return $this->employment->endHistory($tenantId, $periodId);
    }

    /** @return array{current: ?EmploymentPeriod, future: ?EmploymentPeriod} */
    public function basis(int $tenantId, string $employeeId, DateTimeInterface $at): array
    {
        return $this->employment->basis($tenantId, $employeeId, $at);
    }

    /**
     * @template T of EmploymentPeriod|EmploymentTermsVersion
     *
     * @param  array<string, mixed>  $conditions
     * @param  Closure(array<string, mixed>): T  $operation
     * @return T
     */
    private function writeConditions(
        string $evidenceId,
        #[SensitiveParameter] array $conditions,
        ?string $replacesId,
        Closure $operation,
    ): EmploymentPeriod|EmploymentTermsVersion {
        try {
            $conditions = $this->validateTerms($evidenceId, $conditions, $replacesId);

            return $this->keys->withLease(fn () => $operation($conditions));
        } catch (ValidationException $failure) {
            throw ValidationException::withMessages($failure->errors());
        } catch (TenantKeyMaintenanceException) {
            throw new TenantKeyMaintenanceException;
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException('Employment owner is unavailable.');
        } catch (PDOException $failure) {
            $driver = $failure instanceof DeadlockException ? $failure->getPrevious() : $failure;
            $state = (string) ($driver?->getCode() ?? $failure->getCode());
            if ($state === '40001') {
                throw new DeadlockException('The employment transaction must be retried.', 40001);
            }
            if ($state === '40P01' || $failure instanceof DeadlockException) {
                throw new DeadlockException('Employment database deadlock detected.');
            }

            throw new EmploymentTermsWriteException($state);
        } catch (Throwable) {
            // The original trace can hold plaintext in nested callback captures.
            throw new EmploymentTermsWriteException;
        }
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    private function validateTerms(string $evidenceId, #[SensitiveParameter] array $conditions, ?string $replacesId): array
    {
        foreach (['position', 'weekly_hours', 'monthly_hours'] as $field) {
            $value = $conditions[$field] ?? null;
            if (is_string($value) && trim($value) === '') {
                $conditions[$field] = null;
            }
        }

        Validator::make(['evidence_id' => $evidenceId, 'conditions' => $conditions, 'replaces_id' => $replacesId], [
            'evidence_id' => ['required', 'uuid'],
            'replaces_id' => ['nullable', 'uuid'],
            'conditions' => ['required', 'array:contract_type,position,weekly_hours,monthly_hours'],
            'conditions.contract_type' => ['required', 'string', Rule::in(['full_time', 'part_time', 'minijob', 'freelance'])],
            'conditions.position' => ['nullable', 'string', 'max:255'],
            'conditions.weekly_hours' => ['nullable', 'numeric:strict', 'min:0'],
            'conditions.monthly_hours' => ['nullable', 'numeric:strict', 'min:0'],
        ])->validate();

        return $conditions;
    }
}
