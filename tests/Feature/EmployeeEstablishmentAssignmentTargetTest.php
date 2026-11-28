<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Employment\EmploymentPeriod;
use App\Models\Tenant;
use App\Models\TenantKey;
use App\Services\EmployeeEstablishmentAssignmentSchema;
use App\Services\EmployeeEstablishmentAssignmentService;
use App\Services\EmploymentSchema;
use App\Services\EmploymentService;
use App\Services\EstablishmentSchema;
use App\Services\EstablishmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses()->group('serial');

beforeEach(function (): void {
    $this->schema = 'assignment_'.bin2hex(random_bytes(8));
    $this->originalSearchPath = config('database.connections.pgsql.search_path');
    DB::statement('CREATE SCHEMA '.$this->schema);
    config(['database.connections.pgsql.search_path' => $this->schema]);
    DB::purge();

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable('employee_establishment_assignments'))->toBeFalse();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = Tenant::factory()->create();
    $this->employee = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => null]);
    $this->evidence = EmployeeDocument::factory()->contract()->create(['employee_id' => $this->employee->id, 'uploaded_by' => null]);

    // Keep functional Employee and evidence fixtures from the normal schema.
    // Retire the legacy Establishment and its obsolete foreign keys in this
    // private schema before installing the actual cutover definitions.
    DB::statement('DROP TABLE establishments CASCADE');
    app(EstablishmentSchema::class)->apply();
    app(EmploymentSchema::class)->apply();
    app(EmployeeEstablishmentAssignmentSchema::class)->apply();

    $this->establishments = app(EstablishmentService::class);
    $this->assignments = app(EmployeeEstablishmentAssignmentService::class);
    $this->employment = app(EmploymentService::class);
    $this->address = ['street' => 'Hafenstraße', 'house_number' => '12', 'city' => 'Bremen', 'postal_code' => '28217', 'country' => 'DE'];
    $this->first = $this->establishments->create($this->tenant->id, 'Harbour', 'HB-01', $this->address);
    $this->second = $this->establishments->create($this->tenant->id, 'Warehouse', 'WH-01', $this->address);
    $this->start = $this->second->effective_at->addSecond();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
    DB::purge();
    config(['database.connections.pgsql.search_path' => $this->originalSearchPath]);
    DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
});

function startAssignmentEmployment(
    EmploymentService $employment,
    Employee $employee,
    string $evidenceId,
    CarbonImmutable $start,
    ?CarbonImmutable $end = null,
): EmploymentPeriod {
    return $employment->start($employee->tenant_id, $employee->id, $start, $end, $evidenceId,
        ['contract_type' => 'full_time', 'weekly_hours' => 38]);
}

it('starts future employment and transfers in exact adjacent intervals without changing permission or legacy fields', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $transfer = $this->start->addDay()->addMicrosecond();
    $end = $transfer->addDay();
    $legacy = $this->employee->fresh()->getRawOriginal();
    $initial = DB::transaction(function () use ($tenant, $employee, $end) {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $this->start, $end);

        return $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $this->start, $end);
    });
    $new = $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, $transfer, $end, $initial->id);

    expect($this->assignments->at($tenant, $employee, $this->start->subMicrosecond()))->toBeNull()
        ->and($this->assignments->at($tenant, $employee, $transfer->subMicrosecond())['assignment']->id ?? null)->toBe($initial->id)
        ->and($this->assignments->at($tenant, $employee, $transfer)['assignment']->id ?? null)->toBe($new->id)
        ->and($this->assignments->at($tenant, $employee, $transfer)['establishment']->address ?? null)->toEqual($this->address)
        ->and($this->assignments->at($tenant, $employee, $end))->toBeNull()
        ->and($this->employee->fresh()->getRawOriginal())->toBe($legacy);
    expect(fn () => $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, $transfer, $end, $initial->id))
        ->toThrow(DomainException::class);
});

it('rejects a gap at commit and rolls back both employment and its initial terms', function (): void {
    $end = $this->start->addDay();
    expect(fn () => DB::transaction(function () use ($end): void {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $this->start, $end);
        $this->assignments->schedule($this->tenant->id, $this->employee->id, $this->first->establishment_id,
            $this->start->addMicrosecond(), $end);
    }))->toThrow(PDOException::class, '23503');
    expect(DB::table('employment_periods')->count())->toBe(0)
        ->and(DB::table('employment_terms_versions')->count())->toBe(0)
        ->and(DB::table('employee_establishment_assignments')->count())->toBe(0);
});

it('rejects a foreign establishment and hides active and historical assignments of another tenant', function (): void {
    $foreign = Tenant::factory()->create();
    $target = $this->establishments->create($foreign->id, 'Foreign branch', null, $this->address);
    $start = $target->effective_at->addSecond();

    expect(fn () => DB::transaction(function () use ($target, $start): void {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $start);
        $this->assignments->schedule($this->tenant->id, $this->employee->id, $target->establishment_id, $start, null);
    }))->toThrow(ModelNotFoundException::class);
    DB::transaction(function () use ($start): void {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $start);
        $this->assignments->schedule($this->tenant->id, $this->employee->id, $this->first->establishment_id, $start, null);
    });
    expect($this->assignments->at($foreign->id, $this->employee->id, $start))->toBeNull();
});

it('blocks closing an assigned branch and resolves historical names, codes and addresses after closure', function (): void {
    $original = $this->first;
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $start = $this->second->effective_at;
    DB::transaction(function () use ($tenant, $employee, $original, $start): void {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $start);
        $this->assignments->schedule($tenant, $employee, $original->establishment_id, $start, null);
    });
    expect(fn () => $this->establishments->close($tenant, $original->establishment_id))->toThrow(QueryException::class, '23514');
    $changed = $this->establishments->revise($tenant, $original->establishment_id, 'New harbour', 'HB-02',
        [...$this->address, 'house_number' => '14']);
    $initial = $this->assignments->at($tenant, $employee, $start)['assignment'];
    $successor = DB::transaction(function () use ($tenant, $employee, $initial) {
        DB::statement('SET CONSTRAINTS establishment_assignments_within_employment, employment_periods_assignment_coverage IMMEDIATE');

        return $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, null, null, $initial->id);
    });
    $closed = $this->establishments->close($tenant, $original->establishment_id);

    expect($closed->is_closed)->toBeTrue()
        ->and($initial->fresh()->valid_until)->toEqual($successor->valid_from)
        ->and($this->assignments->at($tenant, $employee, $start)['establishment']->id ?? null)->toBe($original->id)
        ->and($this->assignments->at($tenant, $employee, $changed->effective_at)['establishment']->code ?? null)->toBe('HB-02')
        ->and($this->assignments->at($tenant, $employee, $changed->effective_at)['establishment']->address['house_number'] ?? null)->toBe('14')
        ->and($this->assignments->at($tenant, $employee, $closed->effective_at)['establishment']->id ?? null)->toBe($this->second->id);
});

it('resolves separately offset instants in the repeated tenant civil hour', function (): void {
    $year = $this->start->year + 1;
    $civil = CarbonImmutable::parse("last sunday of october {$year}", 'Europe/Berlin')->format('Y-m-d');
    $firstFold = CarbonImmutable::parse($civil.'T02:30:00.123456+02:00');
    $secondFold = CarbonImmutable::parse($civil.'T02:30:00.123456+01:00');
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $original = DB::transaction(function () use ($tenant, $employee, $firstFold) {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $firstFold);

        return $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $firstFold, null);
    });
    $replacement = $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, $secondFold, null, $original->id);

    expect($this->assignments->at($tenant, $employee, $firstFold->utc())['assignment']->id ?? null)->toBe($original->id)
        ->and($this->assignments->at($tenant, $employee, $secondFold->subMicrosecond())['assignment']->id ?? null)->toBe($original->id)
        ->and($this->assignments->at($tenant, $employee, $secondFold->utc())['assignment']->id ?? null)->toBe($replacement->id);
});

it('rejects overlaps and assignment intervals extending beyond employment', function (): void {
    $end = $this->start->addDay();
    $initial = DB::transaction(function () use ($end) {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $this->start, $end);

        return $this->assignments->schedule($this->tenant->id, $this->employee->id, $this->first->establishment_id, $this->start, $end);
    });
    $tenant = $initial->tenant_id;
    $employee = $initial->employee_id;
    $next = $this->start->addHour();
    expect(fn () => $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, $next, $end))
        ->toThrow(QueryException::class, '23P01')
        ->and(fn () => DB::transaction(function () use ($initial, $end): void {
            $nextPeriod = startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $end, $end->addDay());
            DB::table('employee_establishment_assignments')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $initial->tenant_id, 'employee_id' => $initial->employee_id,
                'employment_period_id' => $nextPeriod->id, 'establishment_id' => $initial->establishment_id,
                'valid_from' => $end->format('Y-m-d H:i:s.uP'),
            ]);
        }))->toThrow(PDOException::class, '23503')
        ->and(DB::table('employment_periods')->count())->toBe(1);
});

it('serializes a competing employment end with a transfer and retains the cancelled schedule', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    [$period, $initial] = DB::transaction(function () use ($tenant, $employee): array {
        $period = startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $this->start);
        $assignment = $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $this->start, null);

        return [$period, $assignment];
    });
    $observer = DB::build(config('database.connections.pgsql'));
    $observer->statement("SET lock_timeout TO '250ms'");
    $end = $this->start->addHour();
    $transfer = $end->addHour();
    $endConcurrent = fn () => $observer->table('employment_periods')->where('id', $period->id)->update([
        'valid_until' => $end->format('Y-m-d H:i:s.uP'), 'end_evidence_id' => $this->evidence->id,
    ]);

    try {
        $successor = DB::transaction(function () use ($tenant, $employee, $initial, $observer, $transfer, $endConcurrent) {
            $successor = $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, $transfer, null, $initial->id);
            expect($observer->table('employee_establishment_assignments')->where('employment_period_id', $initial->employment_period_id)->count())->toBe(1)
                ->and($endConcurrent)->toThrow(QueryException::class, '55P03');

            return $successor;
        });
        expect($endConcurrent())->toBe(1)
            ->and($successor->fresh()->getRawOriginal('cancelled_at'))->not->toBeNull()
            ->and($this->assignments->at($tenant, $employee, $end->subMicrosecond())['assignment']->id ?? null)->toBe($initial->id)
            ->and($this->assignments->at($tenant, $employee, $end))->toBeNull()
            ->and(fn () => $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $transfer, null, $initial->id))
            ->toThrow(DomainException::class);
    } finally {
        $observer->disconnect();
    }
});

it('rejects closing against a stale PostgreSQL snapshot after a competing schedule', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $observer = DB::build(config('database.connections.pgsql'));
    $observer->beginTransaction();
    $observer->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    expect($observer->table('employee_establishment_assignments')->count())->toBe(0);

    try {
        DB::transaction(function () use ($tenant, $employee): void {
            startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $this->start);
            $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $this->start, null);
        });

        expect(fn () => $observer->table('establishment_versions')->insert([
            'tenant_id' => $tenant, 'establishment_id' => $this->first->establishment_id,
            'name' => 'Closing test', 'address' => json_encode($this->address, JSON_THROW_ON_ERROR), 'is_closed' => true,
        ]))->toThrow(QueryException::class, '40001');
    } finally {
        $observer->rollBack();
        $observer->disconnect();
    }
    expect(fn () => $this->establishments->close($tenant, $this->first->establishment_id))->toThrow(QueryException::class, '23514');
});

it('rolls back both sides of an interrupted transfer', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $initial = DB::transaction(function () use ($tenant, $employee) {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $this->start);

        return $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $this->start, null);
    });
    $transfer = $this->start->addHour();
    $laterBranch = $this->establishments->create($tenant, 'New branch', null, $this->address);
    expect(fn () => DB::transaction(function () use ($tenant, $employee, $initial, $transfer, $laterBranch): void {
        $this->assignments->schedule($tenant, $employee, $laterBranch->establishment_id, $transfer, null, $initial->id);
        throw new RuntimeException('Interrupted transfer');
    }))->toThrow(RuntimeException::class, 'Interrupted transfer');
    expect($initial->fresh()->valid_until)->toBeNull()
        ->and($this->assignments->at($tenant, $employee, $transfer)['assignment']->id ?? null)->toBe($initial->id)
        ->and(DB::table('employee_establishment_assignments')->count())->toBe(1);
});

it('resolves one committed assignment and history pair across a concurrent transfer and closure', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $start = $this->second->effective_at;
    $initial = DB::transaction(function () use ($tenant, $employee, $start) {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $start);

        return $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $start, null);
    });
    $didTransfer = false;
    DB::listen(function (QueryExecuted $query) use ($tenant, $employee, $initial, &$didTransfer): void {
        if ($didTransfer || ! str_starts_with($query->sql, 'select ') || ! str_contains($query->sql, 'employee_establishment_assignments')) {
            return;
        }

        $didTransfer = true;
        DB::transaction(function () use ($tenant, $employee, $initial): void {
            $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, null, null, $initial->id);
            $this->establishments->close($tenant, $this->first->establishment_id);
        });
    });

    $future = $start->addDay();
    $observed = $this->assignments->at($tenant, $employee, $future);
    expect($didTransfer)->toBeTrue()
        ->and($observed['assignment']->id ?? null)->toBe($initial->id)
        ->and($observed['establishment']->id ?? null)->toBe($this->first->id)
        ->and($this->assignments->at($tenant, $employee, $future)['establishment']->id ?? null)->toBe($this->second->id);
});

it('preserves applied assignment history and accepts a separately evidenced earlier employment end', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $start = $this->second->effective_at;
    [$period, $initial] = DB::transaction(function () use ($tenant, $employee, $start): array {
        $period = startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $start);
        $initial = $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $start, null);

        return [$period, $initial];
    });

    $past = $start->addMicrosecond();
    expect(fn () => $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, $past, null, $initial->id))
        ->toThrow(QueryException::class, '23514')
        ->and($initial->fresh()->valid_until)->toBeNull()
        ->and($this->assignments->at($tenant, $employee, $past)['assignment']->id ?? null)->toBe($initial->id);

    $this->employment->end($tenant, $period->id, $past, $this->evidence->id);

    expect($this->assignments->at($tenant, $employee, $start)['assignment']->id ?? null)->toBe($initial->id)
        ->and($this->assignments->at($tenant, $employee, $past))->toBeNull();
});

it('checks transfer time after acquiring locks even in an older transaction', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $start = $this->second->effective_at;
    $initial = DB::transaction(function () use ($tenant, $employee, $start) {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $start);

        return $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $start, null);
    });
    DB::transaction(function () use ($tenant, $employee, $initial): void {
        $past = CarbonImmutable::parse(DB::scalar('SELECT clock_timestamp()'));
        expect(fn () => $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, $past, null, $initial->id))
            ->toThrow(QueryException::class, '23514');
    });

    expect($initial->fresh()->valid_until)->toBeNull();
});

it('preserves complete coverage during employment end and transfer with immediate temporal foreign keys', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    [$period, $initial] = DB::transaction(function () use ($tenant, $employee): array {
        $period = startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $this->start);
        $assignment = $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $this->start, null);

        return [$period, $assignment];
    });
    $end = $this->start->addDay();
    $transfer = $end->subHour();

    $replacement = DB::transaction(function () use ($tenant, $employee, $period, $initial, $transfer, $end) {
        DB::statement('SET CONSTRAINTS establishment_assignments_within_employment, employment_periods_assignment_coverage IMMEDIATE');
        expect($this->employment->end($tenant, $period->id, $end, $this->evidence->id)->valid_until)->toEqual($end);

        return $this->assignments->schedule($tenant, $employee, $this->second->establishment_id, $transfer, $end, $initial->id);
    });

    expect($this->assignments->at($tenant, $employee, $transfer->subMicrosecond())['assignment']->id ?? null)->toBe($initial->id)
        ->and($this->assignments->at($tenant, $employee, $transfer)['assignment']->id ?? null)->toBe($replacement->id)
        ->and($this->assignments->at($tenant, $employee, $end))->toBeNull();
});

it('rejects a raw shortening that would wait for the period while holding its assignment row', function (): void {
    $tenant = $this->tenant->id;
    $employee = $this->employee->id;
    $initial = DB::transaction(function () use ($tenant, $employee) {
        startAssignmentEmployment($this->employment, $this->employee, $this->evidence->id, $this->start);

        return $this->assignments->schedule($tenant, $employee, $this->first->establishment_id, $this->start, null);
    });
    $observer = DB::build(config('database.connections.pgsql'));
    $observer->beginTransaction();

    try {
        DB::transaction(function () use ($initial, $observer): void {
            DB::table('employment_periods')->where('id', $initial->employment_period_id)->lockForUpdate()->sole();
            $end = $this->start->addDay()->format('Y-m-d H:i:s.uP');

            expect(fn () => $observer->table('employee_establishment_assignments')->where('id', $initial->id)
                ->update(['valid_until' => $end]))->toThrow(QueryException::class, '55P03');
        });
    } finally {
        $observer->rollBack();
        $observer->disconnect();
    }

    expect($initial->fresh()->valid_until)->toBeNull();
});
