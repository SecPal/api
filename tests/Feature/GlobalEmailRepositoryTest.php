<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\GlobalIdentityKeyException;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalEmailLookupVersions;
use App\Services\GlobalIdentity\GlobalEmailRepository;
use App\Services\GlobalIdentity\GlobalEmailSchema;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose as Purpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->rootDirectory = sys_get_temp_dir().'/secpal-email-'.bin2hex(random_bytes(12));
    mkdir($this->rootDirectory, 0700);
    $root = new GlobalIdentityKeyReference('root', 1);
    (new FileGlobalIdentityRootProvider($this->rootDirectory))->provision($root);
    config(['global_identity.root_directory' => $this->rootDirectory]);
    $this->registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $this->registry->initialize($root);
    $this->tenant = TenantKey::factory()->create();
    $this->repository = app(GlobalEmailRepository::class);
    $this->versions = new GlobalEmailLookupVersions(1, [1]);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->rootDirectory);
});

function sealedEmailUser(int $tenantId): User
{
    return new User(['name' => 'Synthetic User', 'password' => 'test-password', 'tenant_id' => $tenantId]);
}

it('leaves the ordinary migration and user factory on the active baseline', function (): void {
    expect(Schema::hasColumn('users', 'email'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email_enc'))->toBeFalse()
        ->and(User::factory()->create(['tenant_id' => $this->tenant->id])->email)->toBeString();
});

it('applies the authoritative schema and preserves original, lookup and delivery semantics', function (): void {
    (new GlobalEmailSchema)->apply();
    $userId = $this->repository->create(sealedEmailUser($this->tenant->id), 'Lo.Cal+Tag@Faß.DE', $this->versions);
    $row = DB::table('users')->where('id', $userId)->selectRaw(
        "email_enc::text AS envelope, encode(email_idx, 'hex') AS index_hex, pg_typeof(email_idx)::text AS index_type, email_version",
    )->sole();

    expect(Schema::hasColumn('users', 'email'))->toBeFalse()
        ->and($row->index_type)->toBe('bytea')
        ->and($row->index_hex)->toHaveLength(64)
        ->and($row->email_version)->toBe(1)
        ->and($row->envelope)->not->toContain('Lo.Cal+Tag', 'Faß', 'xn--fa-hia')
        ->and($this->repository->findUserId('lo.cal+tag@xn--fa-hia.de', $this->versions))->toBe($userId)
        ->and($this->repository->findUserId('lo.cal@xn--fa-hia.de', $this->versions))->toBeNull()
        ->and($this->repository->withEmail($userId, 1, false, fn (#[SensitiveParameter] string $email): string => $email))->toBe('Lo.Cal+Tag@Faß.DE')
        ->and($this->repository->withEmail($userId, 1, true, fn (#[SensitiveParameter] string $email): string => $email))->toBe('Lo.Cal+Tag@xn--fa-hia.de');

    $model = User::query()->findOrFail($userId);
    expect($model->toJson())->not->toContain('email_enc', 'email_idx', $row->index_hex, 'Lo.Cal+Tag');

    DB::enableQueryLog();
    try {
        expect($this->repository->findUserId('lo.cal+tag@xn--fa-hia.de', $this->versions))->toBe($userId);
        $queries = array_column(DB::getQueryLog(), 'query');
        $lookup = array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'from "users"')));
        expect($lookup)->toHaveCount(1)
            ->and($lookup[0])->toContain('id AS user_id', '"email_normalization_version" = ?', '"email_idx_key_version" = ?', '"email_idx" = ?')
            ->not->toContain('"email_enc"', 'select *');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

it('reserves every accepted pair and keeps duplicate and rolled-back writes neutral', function (): void {
    (new GlobalEmailSchema)->apply();
    $original = $this->repository->create(sealedEmailUser($this->tenant->id), 'original@secpal.app', $this->versions);
    $this->registry->activate($this->registry->stage(Purpose::UserEmailLookup));
    $versions = new GlobalEmailLookupVersions(2, [1, 2]);
    $operation = (string) Str::uuid();
    $this->repository->reserve($operation, 'Pending@secpal.app', $versions);
    $this->repository->reserve($operation, 'pending@secpal.app', $versions);

    expect($this->repository->findUserId('original@secpal.app', $versions))->toBe($original)
        ->and(fn () => $this->repository->create(sealedEmailUser($this->tenant->id), 'ORIGINAL@secpal.app', $versions))
        ->toThrow(GlobalIdentityKeyException::class, 'Global email address unavailable.')
        ->and(fn () => $this->repository->create(sealedEmailUser($this->tenant->id), 'pending@secpal.app', $versions))
        ->toThrow(GlobalIdentityKeyException::class, 'Global email address unavailable.');

    expect(fn () => DB::transaction(function () use ($operation, $versions): void {
        $this->repository->create(sealedEmailUser($this->tenant->id), 'Pending@secpal.app', $versions, $operation);
        throw new RuntimeException('Synthetic rollback.');
    }))->toThrow(RuntimeException::class, 'Synthetic rollback.');
    expect($this->repository->findUserId('pending@secpal.app', $versions))->toBeNull();
    $winner = $this->repository->create(sealedEmailUser($this->tenant->id), 'pending@secpal.app', $versions, $operation);
    expect($this->repository->findUserId('Pending@secpal.app', $versions))->toBe($winner);
});

it('rejects stale delivery and authenticated envelopes moved to a different user', function (): void {
    (new GlobalEmailSchema)->apply();
    $first = $this->repository->create(sealedEmailUser($this->tenant->id), 'source@secpal.app', $this->versions);
    $second = $this->repository->create(sealedEmailUser($this->tenant->id), 'target@secpal.app', $this->versions);
    DB::table('users')->where('id', $second)->update([
        'email_enc' => DB::table('users')->where('id', $first)->value('email_enc'),
    ]);
    $unexpected = fn () => throw new RuntimeException('Called across the boundary.');
    Log::spy();

    expect(fn () => $this->repository->withEmail($first, 2, true, $unexpected))->toThrow(GlobalIdentityKeyException::class)
        ->and(fn () => $this->repository->withEmail($second, 1, false, $unexpected))->toThrow(GlobalIdentityKeyException::class);
    Log::shouldHaveReceived('warning')->with('Global email rejected.', [
        'operation' => 'read',
        'reason' => 'invalid_envelope',
    ])->once();
});

it('retains exact historical encryption and atomically activates a reserved address', function (): void {
    (new GlobalEmailSchema)->apply();
    $userId = $this->repository->create(sealedEmailUser($this->tenant->id), 'source@secpal.app', $this->versions);
    $this->registry->activate($this->registry->stage(Purpose::EmailEncryption));
    $operation = (string) Str::uuid();
    $this->repository->reserve($operation, 'next@secpal.app', $this->versions);
    $deliver = static fn (#[SensitiveParameter] string $email): string => $email;

    expect($this->repository->withEmail($userId, 1, true, $deliver))->toBe('source@secpal.app');
    expect(fn () => DB::transaction(function () use ($userId, $operation): void {
        $this->repository->activate($userId, 1, $operation, 'next@secpal.app', $this->versions);
        throw new RuntimeException('Synthetic rollback.');
    }))->toThrow(RuntimeException::class, 'Synthetic rollback.');
    expect($this->repository->findUserId('source@secpal.app', $this->versions))->toBe($userId)
        ->and($this->repository->findUserId('next@secpal.app', $this->versions))->toBeNull()
        ->and($this->repository->activate($userId, 1, strtoupper($operation), 'next@secpal.app', $this->versions))->toBe(2)
        ->and($this->repository->withEmail($userId, 2, true, $deliver))->toBe('next@secpal.app')
        ->and($this->repository->checkEmailVersionForDispatch($userId, 1))->toBeFalse()
        ->and($this->repository->checkEmailVersionForDispatch($userId, 2))->toBeTrue()
        ->and(fn () => $this->repository->withEmail($userId, 1, true, $deliver))->toThrow(GlobalIdentityKeyException::class)
        ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->count())->toBe(0);
});
