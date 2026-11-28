<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\EmploymentSchema;
use App\Services\EmploymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses()->group('serial');

beforeEach(function (): void {
    $this->schema = 'employment_'.bin2hex(random_bytes(8));
    $this->originalSearchPath = config('database.connections.pgsql.search_path');
    DB::statement('CREATE SCHEMA '.$this->schema);
    config(['database.connections.pgsql.search_path' => $this->schema]);
    DB::purge();

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable('employment_periods'))->toBeFalse();
    app(EmploymentSchema::class)->apply();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = Tenant::factory()->create();
    $this->employee = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => null]);
    $this->evidence = EmployeeDocument::factory()->contract()->create(['employee_id' => $this->employee->id, 'uploaded_by' => null]);
    $this->employment = app(EmploymentService::class);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
    DB::purge();
    config(['database.connections.pgsql.search_path' => $this->originalSearchPath]);
    DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
});

function employmentTime(string $value): CarbonImmutable
{
    return CarbonImmutable::parse($value.' UTC');
}

/** @return array{contract_type: string, position: string, weekly_hours: int} */
function employmentConditions(): array
{
    return ['contract_type' => 'full_time', 'position' => 'Synthetic private role', 'weekly_hours' => 38];
}

it('keeps rehire and membership basis separate from identity, access and legacy Employee writes', function (): void {
    $legacy = hash('sha256', serialize($this->employee->fresh()->getRawOriginal()));
    $before = User::query()->count();
    $first = $this->employment->start($this->tenant->id, $this->employee->id,
        employmentTime('2027-01-01'), null, $this->evidence->id, employmentConditions());
    $end = employmentTime('2027-06-01');
    $ending = EmployeeDocument::factory()->create(['employee_id' => $this->employee->id, 'uploaded_by' => null]);
    $this->employment->end($this->tenant->id, $first->id, $end, $ending->id);
    expect($this->employment->basis($this->tenant->id, $this->employee->id, $end))->toBe(['current' => null, 'future' => null])
        ->and($this->employment->termsAt($this->tenant->id, $first->id, $end))->toBeNull();

    $second = $this->employment->start($this->tenant->id, $this->employee->id,
        employmentTime('2027-09-01'), null, $this->evidence->id, employmentConditions());

    expect($this->employment->basis($this->tenant->id, $this->employee->id, employmentTime('2026-12-31'))['future']?->id)->toBe($first->id)
        ->and($this->employment->basis($this->tenant->id, $this->employee->id, $end->subMicrosecond())['current']?->id)->toBe($first->id)
        ->and($this->employment->basis($this->tenant->id, $this->employee->id, $end)['current'])->toBeNull()
        ->and($this->employment->basis($this->tenant->id, $this->employee->id, $end)['future']?->id)->toBe($second->id)
        ->and($this->employment->basis($this->tenant->id, $this->employee->id, employmentTime('2027-09-01'))['current']?->id)->toBe($second->id)
        ->and($second->employee_id)->toBe($first->employee_id)
        ->and(hash('sha256', serialize($this->employee->fresh()->getRawOriginal())))->toBe($legacy)
        ->and(User::query()->count())->toBe($before)
        ->and(Employee::query()->count())->toBe(1);
});

it('resolves future conditions at their exact effective instant and preserves corrected evidence and versions', function (): void {
    $start = employmentTime('2027-01-01');
    $effective = employmentTime('2027-03-01 12:00:00.500123');
    $period = $this->employment->start($this->tenant->id, $this->employee->id, $start, null,
        $this->evidence->id, employmentConditions());
    $amendment = EmployeeDocument::factory()->contract()->create(['employee_id' => $this->employee->id, 'uploaded_by' => null]);
    $initial = $this->employment->termsAt($this->tenant->id, $period->id, $start);
    $future = $this->employment->recordTerms($this->tenant->id, $period->id, $effective, $amendment->id,
        ['contract_type' => 'part_time', 'weekly_hours' => 26.5]);
    $correction = $this->employment->recordTerms($this->tenant->id, $period->id, $effective, $this->evidence->id,
        ['contract_type' => 'part_time', 'weekly_hours' => 24], $future->id);

    expect($this->employment->termsAt($this->tenant->id, $period->id, $start->subMicrosecond()))->toBeNull()
        ->and($this->employment->termsAt($this->tenant->id, $period->id, $effective->subMicrosecond())?->id)->toBe($initial?->id)
        ->and($this->employment->termsAt($this->tenant->id, $period->id, $effective)?->id)->toBe($correction->id)
        ->and($this->employment->termsAt($this->tenant->id, $period->id, $effective, $future->recorded_at)?->id)->toBe($future->id)
        ->and($this->employment->version($this->tenant->id, $period->id, $future->id)->evidence_id)->toBe($amendment->id)
        ->and($future->fresh()->conditions()['weekly_hours'])->toBe(26.5)
        ->and($initial?->conditions()['position'])->toBe('Synthetic private role')
        ->and(DB::table('employment_terms_versions')->where('id', $initial?->id)->value('conditions_enc'))->not->toContain('Synthetic private role')
        ->and($initial?->toArray())->not->toHaveKey('conditions_enc')
        ->and(fn () => $this->employment->recordTerms($this->tenant->id, $period->id, $effective, $this->evidence->id,
            employmentConditions(), $future->id))->toThrow(QueryException::class);
});

it('rejects cross-tenant period operations and evidence for a different employee', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherEmployee = Employee::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherEvidence = EmployeeDocument::factory()->contract()->create(['employee_id' => $otherEmployee->id, 'uploaded_by' => null]);
    $start = employmentTime('2027-01-01');
    $period = $this->employment->start($this->tenant->id, $this->employee->id, $start, null,
        $this->evidence->id, employmentConditions());

    expect(fn () => $this->employment->start($this->tenant->id, $otherEmployee->id, $start, null,
        $otherEvidence->id, employmentConditions()))->toThrow(ModelNotFoundException::class)
        ->and($this->employment->termsAt($otherTenant->id, $period->id, $start))->toBeNull()
        ->and(fn () => $this->employment->recordTerms($this->tenant->id, $period->id, $start->addDay(),
            $otherEvidence->id, employmentConditions()))->toThrow(QueryException::class, '23503')
        ->and(fn () => $this->employment->end($this->tenant->id, $period->id, $start->addDays(2),
            $otherEvidence->id))->toThrow(QueryException::class, '23503')
        ->and($period->fresh()->valid_until)->toBeNull();
});

it('serializes overlapping period writes in PostgreSQL across independent sessions', function (): void {
    $observer = DB::build(config('database.connections.pgsql'));
    $observer->statement("SET lock_timeout TO '250ms'");

    try {
        $overlap = DB::transaction(function () use ($observer): array {
            $period = $this->employment->start($this->tenant->id, $this->employee->id, employmentTime('2027-01-01'), null,
                $this->evidence->id, employmentConditions());
            $overlap = [...$period->getAttributes(), 'id' => Str::uuid()->toString(), 'valid_from' => '2027-03-01T00:00:00Z'];
            expect(fn () => $observer->table('employment_periods')->insert($overlap))->toThrow(QueryException::class, '55P03');

            return $overlap;
        });
        expect(fn () => $observer->table('employment_periods')->insert($overlap))->toThrow(QueryException::class, '23P01')
            ->and(fn () => $this->employment->start($this->tenant->id, $this->employee->id, employmentTime('2027-02-01'),
                employmentTime('2027-05-01'), $this->evidence->id, employmentConditions()))->toThrow(QueryException::class, '23P01');
    } finally {
        $observer->disconnect();
    }
});

it('requires initial terms at commit and forbids rewriting durable terms', function (): void {
    $period = $this->employment->start($this->tenant->id, $this->employee->id, employmentTime('2027-01-01'), employmentTime('2027-03-01'),
        $this->evidence->id, employmentConditions());
    $missingTerms = [...$period->getAttributes(), 'id' => Str::uuid()->toString(),
        'valid_from' => '2027-04-01T00:00:00Z', 'valid_until' => null, 'end_evidence_id' => null];

    expect(fn () => DB::table('employment_periods')->insert($missingTerms))->toThrow(QueryException::class, '23503')
        ->and(fn () => DB::table('employment_terms_versions')->where('employment_period_id', $period->id)
            ->update(['evidence_id' => $this->evidence->id]))->toThrow(QueryException::class, '23514')
        ->and(fn () => DB::statement('TRUNCATE employment_terms_versions CASCADE'))->toThrow(QueryException::class, '23514');
});
