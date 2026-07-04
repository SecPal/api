<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
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

test('keys generate kek command exists and is registered', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('keys:generate-kek');
});

test('keys generate kek command creates the KEK file with secure permissions', function (): void {
    $kekPath = TenantKey::getKekPath();

    $this->artisan('keys:generate-kek')
        ->expectsOutputToContain('Generated KEK successfully')
        ->expectsOutputToContain($kekPath)
        ->expectsOutputToContain('php artisan tenant:setup')
        ->assertExitCode(0);

    expect(file_exists($kekPath))->toBeTrue()
        ->and(filesize($kekPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        ->and(fileperms($kekPath) & 0777)->toBe(0600);
});

test('keys generate kek command refuses to overwrite an existing KEK file', function (): void {
    TenantKey::generateKek();
    $originalContents = file_get_contents(TenantKey::getKekPath());

    $this->artisan('keys:generate-kek')
        ->expectsOutputToContain('KEK file already exists at:')
        ->expectsOutputToContain('php artisan keys:rotate-kek')
        ->assertExitCode(1);

    expect(file_get_contents(TenantKey::getKekPath()))->toBe($originalContents);
});
