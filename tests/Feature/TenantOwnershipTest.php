<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Exceptions\CorruptedEncryptedAttributeException;
use App\Models\Person;
use App\Models\Tenant;
use App\Models\TenantKey;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('constrains every tenant column to a functional tenant in the clean schema', function (): void {
    $unowned = DB::select(<<<'SQL'
        SELECT relation.relname AS table_name
        FROM pg_attribute AS column_definition
        JOIN pg_class AS relation ON relation.oid = column_definition.attrelid
        JOIN pg_namespace AS scope ON scope.oid = relation.relnamespace
        WHERE scope.nspname = current_schema()
          AND relation.relkind IN ('r', 'p')
          AND column_definition.attname = 'tenant_id'
          AND NOT column_definition.attisdropped
          AND NOT EXISTS (
              SELECT 1
              FROM pg_constraint AS ownership
              WHERE ownership.conrelid = relation.oid
                AND ownership.contype = 'f'
                AND ownership.conkey = ARRAY[column_definition.attnum]
                AND ownership.confrelid = to_regclass('tenants')
                AND ownership.confdeltype = 'r'
          )
        ORDER BY relation.relname
        SQL);

    expect($unowned)->toBeEmpty();
});

it('seeds one tenant with its separately identified key and uses it for retained rows', function (): void {
    $this->seed(DatabaseSeeder::class);

    $tenant = Tenant::sole();
    $key = TenantKey::sole();
    expect($key->id)->toBeString()
        ->and($key->tenant_id)->toBe($tenant->id)
        ->and($tenant->key->is($key))->toBeTrue()
        ->and($tenant->users()->count())->toBeGreaterThan(0);
    expect(DB::table('organizational_units')->distinct()->pluck('tenant_id')->all())->toBe([$tenant->id]);
    expect(fn () => DB::transaction(fn () => $tenant->delete()))->toThrow(QueryException::class, '23001');
    expect(fn () => DB::transaction(fn () => TenantKey::factory()->for($tenant)->create()))
        ->toThrow(QueryException::class, '23505');
    expect(fn () => TenantKey::forTenant($key->id))->toThrow(ModelNotFoundException::class);
});

it('rejects a missing key and keys moved between tenant or purpose contexts', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $person = Person::factory()->create([
        'tenant_id' => $tenant->id,
        'email_plain' => 'tenant-fixture@secpal.dev',
    ]);
    $key = $tenant->key;
    $otherKey = $other->key;

    expect($person->fresh()->email_enc)->toBe('tenant-fixture@secpal.dev')
        ->and($key->generateBlindIndex('shared'))
        ->not->toBe($otherKey->generateBlindIndex('shared'));

    $moved = (new Person)->setRawAttributes([...$person->getAttributes(), 'tenant_id' => $other->id]);
    expect(fn () => $moved->email_enc)->toThrow(CorruptedEncryptedAttributeException::class);

    $unprovisioned = Tenant::query()->create();
    $moved->tenant_id = $unprovisioned->id;
    expect(fn () => $moved->email_enc)->toThrow(ModelNotFoundException::class);

    $key->update(['idx_wrapped' => $key->dek_wrapped, 'idx_nonce' => $key->dek_nonce]);
    expect(fn () => $key->generateBlindIndex('shared'))->toThrow(CorruptedEncryptedAttributeException::class);

    $key->update(['dek_wrapped' => $otherKey->dek_wrapped, 'dek_nonce' => $otherKey->dek_nonce]);
    expect(fn () => $person->fresh()->email_enc)->toThrow(CorruptedEncryptedAttributeException::class);
});
