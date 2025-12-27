<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityArchive;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * ActivityArchive Model Tests
 *
 * Validates:
 * - Model configuration (immutability, UUIDs, timestamps)
 * - Relationships (tenant)
 * - Hash chain verification within archives
 * - Scopes (tenant, log name, date filtering)
 * - GDPR compliance (no personal data stored)
 *
 * @see ADR-010 Section 5: Retention & Archiving
 * @see Issue #392 PR-8: Create ActivityArchive model & retention commands
 */
test('activity archive preserves original activity id', function () {
    $tenant = TenantKey::factory()->create();
    $archive = ActivityArchive::create([
        'id' => 12345, // Original Activity log ID (bigint)
        'tenant_id' => $tenant->id,
        'log_name' => 'security',
        'created_at' => now()->subYears(3),
        'event_hash' => hash('sha256', 'test'),
        'previous_hash' => null,
    ]);

    expect($archive->id)->toBe(12345)
        ->and($archive->exists)->toBeTrue();
});

test('activity archive is immutable - no updated_at timestamp', function () {
    $tenant = TenantKey::factory()->create();
    $archive = ActivityArchive::factory()->create(['tenant_id' => $tenant->id]);

    expect($archive)->not->toHaveKey('updated_at')
        ->and(ActivityArchive::UPDATED_AT)->toBeNull();
});

test('activity archive stores only minimal data - no personal information', function () {
    $archive = ActivityArchive::factory()->create();

    // Verify ONLY allowed fields are present
    $fillable = $archive->getFillable();
    expect($fillable)->toContain('id', 'tenant_id', 'log_name', 'created_at', 'event_hash', 'previous_hash')
        ->and($fillable)->not->toContain('properties', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'description')
        ->and($archive)->not->toHaveKeys(['properties', 'subject_type', 'causer_id', 'description']);
});

test('activity archive belongs to tenant', function () {
    $tenant = TenantKey::factory()->create();
    $archive = ActivityArchive::factory()->create(['tenant_id' => $tenant->id]);

    expect($archive->tenant)->toBeInstanceOf(TenantKey::class)
        ->and($archive->tenant->id)->toBe($tenant->id);
});

test('activity archive can verify hash chain with archived predecessor', function () {
    $tenant = TenantKey::factory()->create();

    // Create chain: archive1 → archive2
    $archive1 = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'event_hash' => 'hash1',
        'previous_hash' => null, // Genesis
    ]);

    $archive2 = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'event_hash' => 'hash2',
        'previous_hash' => 'hash1',
    ]);

    expect($archive1->verifyChain())->toBeTrue('Genesis archive should verify')
        ->and($archive2->verifyChain())->toBeTrue('Archive with valid predecessor should verify');
});

test('activity archive can verify hash chain with active predecessor', function () {
    $tenant = TenantKey::factory()->create();

    // Create chain: active → archive (use DB insert to bypass Activity logic)
    DB::table('activity_log')->insert([
        'tenant_id' => (int) $tenant->id,
        'log_name' => 'default',
        'description' => 'Active log',
        'event_hash' => 'active_hash',
        'previous_hash' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $archive = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'event_hash' => 'archive_hash',
        'previous_hash' => 'active_hash',
    ]);

    expect($archive->verifyChain())->toBeTrue('Archive referencing active log should verify');
});

test('activity archive chain verification fails with missing predecessor', function () {
    $tenant = TenantKey::factory()->create();

    $archive = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'event_hash' => 'hash2',
        'previous_hash' => 'nonexistent_hash',
    ]);

    expect($archive->verifyChain())->toBeFalse('Archive with missing predecessor should fail verification');
});

test('activity archive can find next log in chain - active', function () {
    $tenant = TenantKey::factory()->create();

    $archive = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'event_hash' => 'archive_hash',
    ]);

    $nextActiveId = DB::table('activity_log')->insertGetId([
        'tenant_id' => (int) $tenant->id,
        'log_name' => 'default',
        'description' => 'Next active log',
        'event_hash' => 'next_hash',
        'previous_hash' => 'archive_hash',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $next = $archive->nextLog();

    expect($next)->toBeInstanceOf(Activity::class)
        ->and($next->id)->toBe($nextActiveId);
});

test('activity archive can find next log in chain - archived', function () {
    $tenant = TenantKey::factory()->create();

    $archive1 = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'event_hash' => 'hash1',
    ]);

    $archive2 = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'event_hash' => 'hash2',
        'previous_hash' => 'hash1',
    ]);

    $next = $archive1->nextLog();

    expect($next)->toBeInstanceOf(ActivityArchive::class)
        ->and($next->event_hash)->toBe($archive2->event_hash)
        ->and($next->previous_hash)->toBe('hash1');
});

test('activity archive returns null when no next log exists', function () {
    $tenant = TenantKey::factory()->create();

    $archive = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'event_hash' => 'last_hash',
    ]);

    expect($archive->nextLog())->toBeNull();
});

test('activity archive scope for tenant filters correctly', function () {
    $tenant1 = TenantKey::factory()->create();
    $tenant2 = TenantKey::factory()->create();

    ActivityArchive::factory()->count(3)->create(['tenant_id' => $tenant1->id]);
    ActivityArchive::factory()->count(2)->create(['tenant_id' => $tenant2->id]);

    $tenant1Archives = ActivityArchive::forTenant($tenant1->id)->get();
    $tenant2Archives = ActivityArchive::forTenant($tenant2->id)->get();

    expect($tenant1Archives)->toHaveCount(3)
        ->and($tenant2Archives)->toHaveCount(2);
});

test('activity archive scope of log filters by log name', function () {
    $tenant = TenantKey::factory()->create();

    ActivityArchive::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'security',
    ]);
    ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'authentication',
    ]);

    $securityArchives = ActivityArchive::ofLog('security')->get();

    expect($securityArchives)->toHaveCount(2)
        ->and($securityArchives->first()->log_name)->toBe('security');
});

test('activity archive scope older than filters by date', function () {
    $tenant = TenantKey::factory()->create();

    ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subYears(6),
    ]);
    ActivityArchive::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subYears(4),
    ]);
    ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subYear(),
    ]);

    $oldArchives = ActivityArchive::olderThan(now()->subYears(5))->get();

    expect($oldArchives)->toHaveCount(1);
});

test('activity archive preserves merkle tree metadata', function () {
    $tenant = TenantKey::factory()->create();

    $archive = ActivityArchive::factory()->create([
        'tenant_id' => $tenant->id,
        'merkle_root' => 'merkle_root_hash',
        'merkle_batch_id' => 12345,
    ]);

    expect($archive->merkle_root)->toBe('merkle_root_hash')
        ->and($archive->merkle_batch_id)->toBe(12345);
});

test('activity archive created_at is cast to carbon instance', function () {
    $tenant = TenantKey::factory()->create();
    $archive = ActivityArchive::factory()->create(['tenant_id' => $tenant->id]);

    expect($archive->created_at)->toBeInstanceOf(\Carbon\Carbon::class);
});
