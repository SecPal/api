<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    cleanupTestKekFile();
    TenantKey::setKekPath(getTestKekPath());
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('keys generate tenant command exists and is registered', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('keys:generate-tenant');
});

test('keys generate tenant command fails with KEK bootstrap guidance when the KEK file is missing', function (): void {
    $this->artisan('keys:generate-tenant')
        ->expectsOutputToContain('KEK file not found')
        ->expectsOutputToContain('php artisan keys:generate-kek')
        ->assertExitCode(1);
});

test('keys generate tenant command creates a tenant when the KEK exists', function (): void {
    TenantKey::generateKek();

    $this->artisan('keys:generate-tenant')
        ->expectsOutputToContain('Successfully created tenant')
        ->assertExitCode(0);

    expect(TenantKey::count())->toBe(1);
});
