<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('Health Check Endpoints', function () {
    beforeEach(function () {
        cleanupTestKekFile();
        TenantKey::setKekPath(getTestKekPath());
    });

    afterEach(function () {
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
    });
    describe('GET /health/live', function () {
        it('returns 200 OK when application is running', function () {
            $response = $this->getJson('/health/live');

            $response->assertOk();
            $response->assertJson([
                'status' => 'alive',
            ]);
        });

        it('responds in less than 1000ms', function () {
            $start = microtime(true);

            $this->getJson('/health/live');

            $duration = (microtime(true) - $start) * 1000;

            expect($duration)->toBeLessThan(1000);
        });
    });

    describe('GET /health/ready', function () {
        it('returns 200 OK when fully configured', function () {
            // Create tenant key directly in database
            // Note: tenant_keys uses base64-encoded strings in varchar(64), not binary data
            DB::table('tenant_keys')->insert([
                'dek_wrapped' => base64_encode(random_bytes(32)),
                'dek_nonce' => base64_encode(random_bytes(24)),
                'idx_wrapped' => base64_encode(random_bytes(32)),
                'idx_nonce' => base64_encode(random_bytes(24)),
                'key_version' => 1,
                'created_at' => now(),
            ]);

            // Generate KEK file for test
            TenantKey::generateKek();

            $response = $this->getJson('/health/ready');

            $response->assertOk();
            $response->assertJson([
                'status' => 'ready',
                'checks' => [
                    'database' => 'ok',
                    'tenant_keys' => 'ok',
                    'kek_file' => 'ok',
                ],
            ]);
        });

        it('returns 503 when tenant key is missing', function () {
            // No tenant key created
            // Generate KEK file for test
            TenantKey::generateKek();

            $response = $this->getJson('/health/ready');

            $response->assertStatus(503);
            $response->assertJson([
                'status' => 'not_ready',
                'checks' => [
                    'database' => 'ok',
                    'tenant_keys' => 'missing',
                    'kek_file' => 'ok',
                ],
            ]);
        });

        it('returns 503 when KEK file is missing', function () {
            // Create tenant key directly in database
            // Note: tenant_keys uses base64-encoded strings in varchar(64), not binary data
            DB::table('tenant_keys')->insert([
                'dek_wrapped' => base64_encode(random_bytes(32)),
                'dek_nonce' => base64_encode(random_bytes(24)),
                'idx_wrapped' => base64_encode(random_bytes(32)),
                'idx_nonce' => base64_encode(random_bytes(24)),
                'key_version' => 1,
                'created_at' => now(),
            ]);

            // KEK file does not exist
            TenantKey::setKekPath('/nonexistent/path/kek.key');

            $response = $this->getJson('/health/ready');

            $response->assertStatus(503);
            $response->assertJson([
                'status' => 'not_ready',
                'checks' => [
                    'database' => 'ok',
                    'tenant_keys' => 'ok',
                    'kek_file' => 'missing',
                ],
            ]);
        });
    });
});
