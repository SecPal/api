<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('Health Check Endpoints', function () {
    describe('GET /health/live', function () {
        it('returns 200 OK when application is running', function () {
            $response = $this->getJson('/health/live');

            $response->assertOk();
            $response->assertJson([
                'status' => 'alive',
            ]);
        });

        it('responds in less than 100ms', function () {
            $start = microtime(true);

            $this->getJson('/health/live');

            $duration = (microtime(true) - $start) * 1000;

            expect($duration)->toBeLessThan(100);
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

            // Mock KEK file exists
            $kekPath = __DIR__.'/../Fixtures/test_kek.key';
            if (! is_dir(__DIR__.'/../Fixtures')) {
                mkdir(__DIR__.'/../Fixtures', 0755, true);
            }
            config(['app.kek_path' => $kekPath]);
            file_put_contents($kekPath, random_bytes(32));

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

            // Cleanup
            if (file_exists($kekPath)) {
                unlink($kekPath);
            }
        });

        it('returns 503 when tenant key is missing', function () {
            // No tenant key created
            // Mock KEK file exists
            $kekPath = __DIR__.'/../Fixtures/test_kek.key';
            if (! is_dir(__DIR__.'/../Fixtures')) {
                mkdir(__DIR__.'/../Fixtures', 0755, true);
            }
            config(['app.kek_path' => $kekPath]);
            file_put_contents($kekPath, random_bytes(32));

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

            // Cleanup
            if (file_exists($kekPath)) {
                unlink($kekPath);
            }
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
            config(['app.kek_path' => '/nonexistent/path/kek.key']);

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
