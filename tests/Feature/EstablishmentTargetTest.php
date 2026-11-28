<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\EstablishmentSchema;
use App\Services\EstablishmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->schema = 'establishment_'.bin2hex(random_bytes(8));
    $this->originalSearchPath = config('database.connections.pgsql.search_path');
    DB::statement('CREATE SCHEMA '.$this->schema);
    config(['database.connections.pgsql.search_path' => $this->schema]);
    DB::purge();

    $migration = require database_path('migrations/2025_11_01_165632_create_tenants_table.php');
    $migration->up();
    app(EstablishmentSchema::class)->apply();
    $this->tenant = Tenant::query()->create();
    $this->service = app(EstablishmentService::class);
});

afterEach(function (): void {
    DB::purge();
    config(['database.connections.pgsql.search_path' => $this->originalSearchPath]);
    DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
});

function establishmentAddress(): array
{
    return ['street' => 'Hauptstraße', 'house_number' => '12a', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE'];
}

it('gives two establishments at the same address independent identities under only the tenant', function (): void {
    $first = $this->service->create($this->tenant->id, 'Operations', 'OPS', establishmentAddress());
    $second = $this->service->create($this->tenant->id, 'Training', 'TRN', establishmentAddress());

    expect($first->establishment_id)->not->toBe($second->establishment_id)
        ->and($first->address)->toEqual($second->address)
        ->and($first->tenant_id)->toBe($this->tenant->id)
        ->and($this->service->current($this->tenant->id, $second->establishment_id)->name)->toBe('Training')
        ->and(Schema::getColumnListing('establishments'))->toEqualCanonicalizing(['id', 'tenant_id'])
        ->and(Schema::hasTable('legal_entities'))->toBeFalse()
        ->and(Schema::hasTable('organizational_units'))->toBeFalse()
        ->and(Schema::hasTable('sites'))->toBeFalse()
        ->and(Schema::hasTable('tenant_memberships'))->toBeFalse();
});

it('rejects foreign references in both the service and the composite database binding', function (): void {
    $other = Tenant::query()->create();
    $first = $this->service->create($this->tenant->id, 'Operations', null, establishmentAddress());

    expect(fn () => $this->service->current($other->id, $first->establishment_id))->toThrow(ModelNotFoundException::class)
        ->and($this->service->at($other->id, $first->establishment_id, $first->effective_at))->toBeNull()
        ->and(fn () => $this->service->close($other->id, $first->establishment_id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => DB::transaction(fn () => $this->service->lockAssignable($other->id, $first->establishment_id)))->toThrow(ModelNotFoundException::class);

    $foreign = $first->getAttributes();
    unset($foreign['id'], $foreign['effective_at']);
    $foreign['tenant_id'] = $other->id;
    expect(fn () => DB::table('establishment_versions')->insert($foreign))->toThrow(QueryException::class, '23503');
});

it('resolves historical facts at each exact boundary after rename, readdress, recode and closure', function (): void {
    $first = $this->service->create($this->tenant->id, 'Operations', 'OPS', establishmentAddress());
    $id = $first->establishment_id;
    $newAddress = [...establishmentAddress(), 'house_number' => '18'];
    $revised = $this->service->revise($this->tenant->id, $id, 'Field office', 'FIELD', $newAddress);
    $closed = $this->service->close($this->tenant->id, $id);

    expect($this->service->at($this->tenant->id, $id, $first->effective_at->subMicrosecond()))->toBeNull()
        ->and($this->service->at($this->tenant->id, $id, $first->effective_at)?->name)->toBe('Operations')
        ->and($this->service->at($this->tenant->id, $id, $revised->effective_at->subMicrosecond())?->code)->toBe('OPS')
        ->and($this->service->at($this->tenant->id, $id, $revised->effective_at)?->address)->toEqual($newAddress)
        ->and($this->service->at($this->tenant->id, $id, $closed->effective_at->subMicrosecond())?->closedAt())->toBeNull()
        ->and($this->service->at($this->tenant->id, $id, $closed->effective_at)?->closedAt())->toEqual($closed->effective_at)
        ->and($this->service->current($this->tenant->id, $id)->name)->toBe('Field office')
        ->and($this->service->close($this->tenant->id, $id)->id)->toBe($closed->id)
        ->and(fn () => $this->service->revise($this->tenant->id, $id, 'Reopen', null, $newAddress))->toThrow(DomainException::class)
        ->and(fn () => DB::transaction(fn () => $this->service->lockAssignable($this->tenant->id, $id)))->toThrow(DomainException::class);
});

it('retains both the identity and historical data against hard deletion or in-place edits', function (): void {
    $first = $this->service->create($this->tenant->id, 'Operations', null, establishmentAddress());
    expect(fn () => DB::table('establishments')->where('id', $first->establishment_id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('establishment_versions')->where('id', $first->id)->delete())->toThrow(QueryException::class, '23514')
        ->and(fn () => DB::table('establishment_versions')->where('id', $first->id)->update(['name' => 'Changed']))->toThrow(QueryException::class, '23514')
        ->and($this->service->current($this->tenant->id, $first->establishment_id)->name)->toBe('Operations');
});

it('keeps the assignability lock until the caller transaction ends', function (): void {
    $first = $this->service->create($this->tenant->id, 'Operations', null, establishmentAddress());
    $id = $first->establishment_id;
    $observer = DB::build(config('database.connections.pgsql'));

    try {
        expect(fn () => $this->service->lockAssignable($this->tenant->id, $id))->toThrow(LogicException::class);
        DB::transaction(function () use ($observer, $first, $id): void {
            expect($this->service->lockAssignable($this->tenant->id, $id)->id)->toBe($first->id);
            expect(fn () => $observer->table('establishments')->where('id', $id)->lock('for update nowait')->first())
                ->toThrow(QueryException::class, '55P03');
        });

        expect($observer->table('establishments')->where('id', $id)->lock('for update nowait')->first())->not->toBeNull();
    } finally {
        $observer->disconnect();
    }
});

it('rolls back establishment and version writes together', function (): void {
    expect(fn () => DB::transaction(function (): void {
        $this->service->create($this->tenant->id, 'Operations', null, establishmentAddress());

        throw new RuntimeException('Synthetic interruption');
    }))->toThrow(RuntimeException::class, 'Synthetic interruption');

    expect(DB::table('establishments')->count())->toBe(0)
        ->and(DB::table('establishment_versions')->count())->toBe(0);
});

it('validates the address contract before creating persistence', function (): void {
    $address = [...establishmentAddress(), 'latitude' => 91];
    expect(fn () => $this->service->create($this->tenant->id, 'Operations', null, $address))
        ->toThrow(ValidationException::class);

    expect(DB::table('establishments')->count())->toBe(0);
});

it('invalidates an older database snapshot when a close commits', function (): void {
    $first = $this->service->create($this->tenant->id, 'Operations', null, establishmentAddress());
    $id = $first->establishment_id;
    $observer = DB::build(config('database.connections.pgsql'));

    try {
        $observer->beginTransaction();
        $observer->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        expect($observer->table('establishments')->where('id', $id)->first())->not->toBeNull();

        $this->service->close($this->tenant->id, $id);

        expect(fn () => $observer->table('establishments')->where('id', $id)->lockForUpdate()->first())
            ->toThrow(QueryException::class, '40001');
    } finally {
        $observer->rollBack();
        $observer->disconnect();
    }
});

it('rejects direct backdating of establishment history', function (): void {
    $first = $this->service->create($this->tenant->id, 'Operations', null, establishmentAddress());
    $attributes = $first->getAttributes();
    unset($attributes['id'], $attributes['effective_at']);
    $backdated = [...$attributes, 'effective_at' => $first->effective_at->subDay()->format('Y-m-d H:i:s.uP')];

    expect(fn () => DB::table('establishment_versions')->insert($backdated))->toThrow(QueryException::class, '23514');
});

it('rejects a direct append after terminal closure', function (): void {
    $first = $this->service->create($this->tenant->id, 'Operations', null, establishmentAddress());
    $attributes = $first->getAttributes();
    unset($attributes['id'], $attributes['effective_at']);

    $closed = $this->service->close($this->tenant->id, $first->establishment_id);
    expect(fn () => DB::table('establishment_versions')->insert($attributes))->toThrow(QueryException::class, '23514')
        ->and($this->service->current($this->tenant->id, $first->establishment_id)->id)->toBe($closed->id);
});

it('stores blank optional GPS coordinates as null', function (): void {
    $address = [...establishmentAddress(), 'latitude' => '', 'longitude' => '  '];
    $version = $this->service->create($this->tenant->id, 'Operations', null, $address);
    expect($version->address['latitude'])->toBeNull()->and($version->address['longitude'])->toBeNull();
});
