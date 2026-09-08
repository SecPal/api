<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('serial');

test('the PostgreSQL 18 fixture exercises SecPal database persistence and locking semantics', function (): void {
    $version = (int) DB::scalar('SHOW server_version_num');

    expect($version)->toBeGreaterThanOrEqual(180000)
        ->and(Schema::hasTable('migrations'))->toBeTrue()
        ->and(Schema::hasTable('cache'))->toBeTrue()
        ->and(Schema::hasTable('jobs'))->toBeTrue()
        ->and(Schema::hasTable('sessions'))->toBeTrue();

    $rollbackKey = 'pg18-rollback-'.Str::uuid();

    try {
        DB::transaction(function () use ($rollbackKey): void {
            DB::table('cache')->insert([
                'key' => $rollbackKey,
                'value' => 'rolled-back',
                'expiration' => now()->addMinute()->getTimestamp(),
            ]);

            throw new RuntimeException('rollback probe');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('rollback probe');
    }

    expect(DB::table('cache')->where('key', $rollbackKey)->exists())->toBeFalse();

    $lockKey = 'pg18-for-update-'.Str::uuid();
    DB::table('cache')->insert([
        'key' => $lockKey,
        'value' => 'lockable',
        'expiration' => now()->addMinute()->getTimestamp(),
    ]);

    DB::transaction(function () use ($lockKey): void {
        expect(DB::table('cache')->where('key', $lockKey)->lockForUpdate()->first())->not->toBeNull();
    });

    $advisoryLock = 14510018;
    config()->set('database.connections.pg18_fixture_primary', config('database.connections.pgsql'));
    config()->set('database.connections.pg18_fixture_probe', config('database.connections.pgsql'));
    DB::purge('pg18_fixture_primary');
    DB::purge('pg18_fixture_probe');
    $primary = DB::connection('pg18_fixture_primary');
    $probe = DB::connection('pg18_fixture_probe');

    $primary->beginTransaction();
    $primary->select('SELECT pg_advisory_xact_lock(?)', [$advisoryLock]);

    expect((bool) $probe->scalar('SELECT pg_try_advisory_xact_lock(?)', [$advisoryLock]))->toBeFalse();
    $primary->commit();

    expect((bool) $probe->scalar('SELECT pg_try_advisory_xact_lock(?)', [$advisoryLock]))->toBeTrue();
    DB::disconnect('pg18_fixture_primary');
    DB::disconnect('pg18_fixture_probe');

    expect(DB::scalar("SELECT jsonb_typeof('{\"fixture\": true}'::jsonb)"))->toBe('object')
        ->and(DB::scalar("SELECT '2b2854dd-5924-4ce3-93be-f3cb38b7aabb'::uuid::text"))
        ->toBe('2b2854dd-5924-4ce3-93be-f3cb38b7aabb');

    $constraintKey = 'pg18-constraint-'.Str::uuid();
    DB::table('cache')->insert([
        'key' => $constraintKey,
        'value' => 'unique',
        'expiration' => now()->addMinute()->getTimestamp(),
    ]);

    expect(fn (): mixed => DB::transaction(fn (): bool => DB::table('cache')->insert([
        'key' => $constraintKey,
        'value' => 'duplicate',
        'expiration' => now()->addMinute()->getTimestamp(),
    ])))->toThrow(QueryException::class);

    $cacheKey = 'pg18-cache-'.Str::uuid();
    Cache::store('database')->put($cacheKey, 'persisted', now()->addMinute());
    expect(Cache::store('database')->get($cacheKey))->toBe('persisted')
        ->and(DB::table('cache')->where('key', 'like', '%'.$cacheKey)->exists())->toBeTrue();

    Queue::connection('database')->pushRaw(json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => 'PostgreSQL 18 fixture probe',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [],
    ], JSON_THROW_ON_ERROR));
    expect(DB::table('jobs')->count())->toBe(1);

    $session = app('session')->driver();
    $session->setId('pg18-session-'.Str::random(24));
    $session->start();
    $session->put('fixture', 'persisted');
    $session->save();

    expect(DB::table('sessions')->where('id', $session->getId())->value('payload'))->not->toBeNull();
});
