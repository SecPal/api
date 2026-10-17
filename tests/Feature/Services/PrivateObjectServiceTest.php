<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\TenantKey;
use App\Services\PrivateObjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::factory()->create();
    $this->employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    fakePrivateObjects();
    config([
        'private_objects.mode' => 'self-hosted',
        'private_objects.disk' => 'private_objects',
        'private_objects.purpose' => 'api',
        'private_objects.replication_targets' => ['recovery-primary'],
    ]);
    $this->objects = app(PrivateObjectService::class);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function attachPrivateEmployeeObject($test, array $reservation): EmployeeDocument
{
    return DB::transaction(function () use ($test, $reservation): EmployeeDocument {
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $test->employee->id,
            'file_path' => $reservation['object_key'],
        ]);
        $test->objects->publish($reservation['id'], $test->tenant, 'employee-document', $document->id);

        return $document;
    });
}

test('publishes exact encrypted bytes with business reference and replication intent on PostgreSQL', function (): void {
    expect(DB::selectOne('select version() as v')->v)->toContain('PostgreSQL 18.');
    $reservation = $this->objects->stage($this->tenant, 'employee-document', 'private document');
    expect($reservation['object_key'])->toMatch('/^[0-9a-f]{32}$/');
    $bytes = Storage::disk('private_objects')->get($reservation['object_key']);
    expect($bytes)->not->toContain('private document');
    expect(hash('sha256', $bytes))->toBe($reservation['storage_sha256']);
    expect(strlen($bytes))->toBe($reservation['stored_bytes']);
    $document = attachPrivateEmployeeObject($this, $reservation);
    expect($this->objects->read($this->tenant, 'employee-document', $document->id))->toBe('private document');
    $this->assertDatabaseHas('private_objects', ['id' => $reservation['id'], 'state' => 'READY']);
    $this->assertDatabaseHas('private_object_references', ['object_id' => $reservation['id'], 'employee_document_id' => $document->id]);
    $this->assertDatabaseHas('object_replication_intents', ['object_id' => $reservation['id'], 'target' => 'recovery-primary']);
});

test('outer transaction rollback leaves only a pending orphan and never deletes immutable bytes', function (): void {
    $reservation = $this->objects->stage($this->tenant, 'employee-document', 'orphan');
    try {
        DB::transaction(function () use ($reservation): void {
            attachPrivateEmployeeObject($this, $reservation);
            throw new RuntimeException('synthetic business failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('synthetic business failure');
    }
    $this->assertDatabaseHas('private_objects', ['id' => $reservation['id'], 'state' => 'PENDING']);
    $this->assertDatabaseMissing('private_object_references', ['object_id' => $reservation['id']]);
    $this->assertDatabaseMissing('object_replication_intents', ['object_id' => $reservation['id']]);
    Storage::disk('private_objects')->assertExists($reservation['object_key']);
});

test('rejects corrupt bytes missing objects wrong tenant purpose and an unowned publication', function (): void {
    $reservation = $this->objects->stage($this->tenant, 'employee-document', 'integrity');
    $document = attachPrivateEmployeeObject($this, $reservation);
    $other = TenantKey::factory()->create();
    expect(fn () => $this->objects->read($other, 'employee-document', $document->id))
        ->toThrow(RuntimeException::class);
    expect(fn () => $this->objects->read($this->tenant, 'onboarding-file', $document->id))
        ->toThrow(RuntimeException::class);
    Storage::disk('private_objects')->put($reservation['object_key'], str_repeat('x', $reservation['stored_bytes']));
    expect(fn () => $this->objects->read($this->tenant, 'employee-document', $document->id))
        ->toThrow(RuntimeException::class);
    Storage::disk('private_objects')->put($reservation['object_key'], 'truncated');
    expect(fn () => $this->objects->read($this->tenant, 'employee-document', $document->id))
        ->toThrow(RuntimeException::class);
    Storage::disk('private_objects')->delete($reservation['object_key']);
    expect(fn () => $this->objects->read($this->tenant, 'employee-document', $document->id))
        ->toThrow(RuntimeException::class);
});

test('tombstones business authority without physical delete or retention release', function (): void {
    $reservation = $this->objects->stage($this->tenant, 'employee-document', 'retained');
    $document = attachPrivateEmployeeObject($this, $reservation);
    DB::transaction(function () use ($document): void {
        $this->objects->tombstone($this->tenant, 'employee-document', $document->id);
        $document->delete();
    });
    expect(fn () => $this->objects->read($this->tenant, 'employee-document', $document->id))
        ->toThrow(RuntimeException::class);
    $this->assertDatabaseHas('private_objects', ['id' => $reservation['id'], 'state' => 'TOMBSTONED']);
    Storage::disk('private_objects')->assertExists($reservation['object_key']);
});

test('refuses Managed local authority and non-product service purposes', function (): void {
    config(['private_objects.mode' => 'managed']);
    expect(fn () => $this->objects->stage($this->tenant, 'employee-document', 'must not publish'))
        ->toThrow(RuntimeException::class);
    config(['private_objects.mode' => 'self-hosted', 'private_objects.purpose' => 'frontend']);
    expect(fn () => $this->objects->stage($this->tenant, 'employee-document', 'must not publish'))
        ->toThrow(RuntimeException::class);
    expect(Storage::disk('private_objects')->allFiles())->toBe([]);
});

test('create-only collisions preserve the first exact bytes and missing acknowledgements cannot become READY', function (): void {
    $backend = app(App\Storage\ImmutablePrivateObjectBackend::class);
    $key = str_replace('-', '', (string) Illuminate\Support\Str::uuid());
    $first = random_bytes(64);
    $backend->create($key, $first);
    expect(fn () => $backend->create($key, random_bytes(64)))->toThrow(RuntimeException::class);
    expect($backend->read($key, strlen($first)))->toBe($first);
    $this->app->instance(App\Storage\ImmutablePrivateObjectBackend::class, new class extends App\Storage\ImmutablePrivateObjectBackend
    {
        public function create(string $key, string $bytes): void
        {
            parent::create($key, $bytes);
            throw new RuntimeException('synthetic lost PUT acknowledgement');
        }
    });
    $objects = app(PrivateObjectService::class);
    expect(fn () => $objects->stage($this->tenant, 'employee-document', 'lost response'))
        ->toThrow(RuntimeException::class, 'Private Object durable publication failed');
    $object = DB::table('private_objects')->where('tenant_id', $this->tenant->id)->sole();
    expect($object->state)->toBe('PENDING')->and($object->verified_at)->toBeNull();
    $document = EmployeeDocument::factory()->create([
        'employee_id' => $this->employee->id, 'file_path' => $object->object_key,
    ]);
    expect(fn () => DB::transaction(fn () => $objects->publish(
        $object->id, $this->tenant, 'employee-document', $document->id,
    )))->toThrow(RuntimeException::class);
    Storage::disk('private_objects')->assertExists($object->object_key);
    $this->assertDatabaseMissing('private_object_references', ['object_id' => $object->id]);
});

test('retains the wrapped encryption generation and rejects unrelated runtime authority', function (): void {
    $reservation = $this->objects->stage($this->tenant, 'employee-document', 'historical ciphertext');
    $document = attachPrivateEmployeeObject($this, $reservation);
    $this->tenant->update(TenantKey::generateEnvelopeKeys() + ['key_version' => 2]);
    expect($this->objects->read($this->tenant, 'employee-document', $document->id))->toBe('historical ciphertext');
    config(['private_objects.purpose' => 'frontend']);
    expect(fn () => $this->objects->read($this->tenant, 'employee-document', $document->id))
        ->toThrow(RuntimeException::class, 'Private Object runtime purpose is not authorized');
});

test('explicit legacy import preserves encrypted bytes without activating a local-file fallback', function (): void {
    Storage::fake('local');
    $encrypted = $this->tenant->encrypt('legacy encrypted document');
    $blob = json_encode([
        'ciphertext' => base64_encode($encrypted['ciphertext']),
        'nonce' => base64_encode($encrypted['nonce']),
    ], JSON_THROW_ON_ERROR);
    $document = EmployeeDocument::factory()->create([
        'employee_id' => $this->employee->id, 'file_path' => 'employees/legacy/document.enc',
    ]);
    Storage::disk('local')->put($document->file_path, $blob);
    $this->artisan('private-objects:import', ['purpose' => 'employee-document', 'id' => $document->id])->assertSuccessful();
    $document->refresh();
    expect(Storage::disk('private_objects')->get($document->file_path))->toBe($blob);
    expect($this->objects->read($this->tenant, 'employee-document', $document->id))->toBe('legacy encrypted document');
    Storage::disk('local')->assertExists('employees/legacy/document.enc');
});

test('rejects symlink substitution even when it exposes the expected ciphertext', function (): void {
    $reservation = $this->objects->stage($this->tenant, 'employee-document', 'immutable link boundary');
    $document = attachPrivateEmployeeObject($this, $reservation);
    $disk = Storage::disk('private_objects');
    $path = $disk->path($reservation['object_key']);
    $copy = $disk->path('substituted-link-target');
    copy($path, $copy);
    unlink($path);
    symlink($copy, $path);
    expect(fn () => $this->objects->read($this->tenant, 'employee-document', $document->id))
        ->toThrow(RuntimeException::class);
});

test('imports retained soft-deleted legacy ciphertext without restoring business read authority', function (): void {
    Storage::fake('local');
    $encrypted = $this->tenant->encrypt('retained legacy bytes');
    $blob = json_encode(['ciphertext' => base64_encode($encrypted['ciphertext']), 'nonce' => base64_encode($encrypted['nonce'])], JSON_THROW_ON_ERROR);
    $document = EmployeeDocument::factory()->create([
        'employee_id' => $this->employee->id, 'file_path' => 'employees/legacy/retained.enc',
    ]);
    Storage::disk('local')->put($document->file_path, $blob);
    $document->delete();
    $this->artisan('private-objects:import', ['purpose' => 'employee-document', 'id' => $document->id])->assertSuccessful();
    $document = EmployeeDocument::withTrashed()->findOrFail($document->id);
    $object = DB::table('private_objects')->where('object_key', $document->file_path)->sole();
    expect($object->state)->toBe('TOMBSTONED');
    expect(Storage::disk('private_objects')->get($object->object_key))->toBe($blob);
    expect(fn () => $this->objects->read($this->tenant, 'employee-document', $document->id))->toThrow(RuntimeException::class);
});
