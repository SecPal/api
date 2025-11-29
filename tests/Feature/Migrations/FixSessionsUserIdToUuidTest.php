<?php

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('sessions table exists', function (): void {
    expect(Schema::hasTable('sessions'))->toBeTrue();
});

test('sessions.user_id supports UUID values', function (): void {
    $columns = Schema::getColumns('sessions');
    $userIdColumn = collect($columns)->firstWhere('name', 'user_id');

    expect($userIdColumn)->not->toBeNull();
    // Should be varchar(36) to store UUIDs
    expect($userIdColumn['type_name'])->toBe('varchar');
});

test('sessions.user_id is nullable', function (): void {
    $columns = Schema::getColumns('sessions');
    $userIdColumn = collect($columns)->firstWhere('name', 'user_id');

    expect($userIdColumn)->not->toBeNull();
    expect($userIdColumn['nullable'])->toBeTrue();
});

test('sessions can store UUID user_id', function (): void {
    $uuid = (string) \Illuminate\Support\Str::uuid();

    // Insert a session with UUID user_id
    DB::table('sessions')->insert([
        'id' => 'test-session-id',
        'user_id' => $uuid,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit Test',
        'payload' => base64_encode('test'),
        'last_activity' => time(),
    ]);

    // Verify it was stored correctly
    $session = DB::table('sessions')->where('id', 'test-session-id')->first();
    expect($session->user_id)->toBe($uuid);
});

test('sessions can store null user_id for unauthenticated sessions', function (): void {
    // Insert a session without user_id (unauthenticated visitor)
    DB::table('sessions')->insert([
        'id' => 'anon-session-id',
        'user_id' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit Test',
        'payload' => base64_encode('test'),
        'last_activity' => time(),
    ]);

    // Verify it was stored correctly
    $session = DB::table('sessions')->where('id', 'anon-session-id')->first();
    expect($session->user_id)->toBeNull();
});
