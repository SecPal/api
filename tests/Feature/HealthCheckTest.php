<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use App\Services\RuntimeHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('Health Check Endpoints', function () {
    beforeEach(function () {
        cleanupTestKekFile();
        incrementTestKekCounter();
        TenantKey::setKekPath(getTestKekPath());
    });

    afterEach(function () {
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
    });

    describe('GET /up', function () {
        it('returns 404 because the built-in Laravel health route is disabled', function () {
            $this->get('/up')->assertNotFound();
        });
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

    describe('GET /health', function () {
        it('returns public health metadata without exposing the application version', function () {
            $response = $this->getJson('/health');

            $response->assertOk()
                ->assertJson([
                    'status' => 'ok',
                    'service' => 'SecPal API',
                ]);

            expect($response->json())->not->toHaveKey('version');
        });
    });

    describe('GET /health/ready', function () {
        it('returns 200 OK when fully configured', function () {
            $runtimeHeartbeatService = app(RuntimeHeartbeatService::class);

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
            $runtimeHeartbeatService->recordSchedulerHeartbeat();

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 200, 'ready');
        });

        it('returns 503 when tenant key is missing', function () {
            app(RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();

            // No tenant key created
            // Generate KEK file for test
            TenantKey::generateKek();

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 503, 'not_ready');
        });

        it('returns 503 when KEK file is missing', function () {
            app(RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();

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

            assertPublicReadinessResponse($response, 503, 'not_ready');
        });

        it('returns 503 when the scheduler heartbeat is missing', function () {
            seedHealthReadinessPrerequisites();

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 503, 'not_ready');
        });

        it('returns 503 when the default queue has pending jobs without a fresh worker heartbeat', function () {
            seedHealthReadinessPrerequisites();
            app(RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();
            seedPendingJob('default');

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 503, 'not_ready');
        });

        it('returns 503 when the forensics queue has pending jobs without a fresh worker heartbeat', function () {
            seedHealthReadinessPrerequisites();
            app(RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();
            seedPendingJob('opentimestamp');

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 503, 'not_ready');
        });

        it('returns 200 when pending queue jobs still have a fresh worker heartbeat', function () {
            seedHealthReadinessPrerequisites();

            $runtimeHeartbeatService = app(RuntimeHeartbeatService::class);
            $runtimeHeartbeatService->recordSchedulerHeartbeat();
            $runtimeHeartbeatService->recordQueueHeartbeat('default');
            seedPendingJob('default');

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 200, 'ready');
        });

        it('keeps readiness green when queue workers are idle with stale heartbeats', function () {
            seedHealthReadinessPrerequisites();

            $runtimeHeartbeatService = app(RuntimeHeartbeatService::class);
            $runtimeHeartbeatService->recordSchedulerHeartbeat();
            $runtimeHeartbeatService->recordQueueHeartbeat('default', now()->subHour());

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 200, 'ready');
        });

        it('returns 503 when the scheduler heartbeat is stale', function () {
            seedHealthReadinessPrerequisites();

            app(RuntimeHeartbeatService::class)->recordSchedulerHeartbeat(now()->subMinutes(10));

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 503, 'not_ready');
        });

        it('returns 503 when the default queue worker heartbeat is stale with pending jobs', function () {
            seedHealthReadinessPrerequisites();

            $runtimeHeartbeatService = app(RuntimeHeartbeatService::class);
            $runtimeHeartbeatService->recordSchedulerHeartbeat();
            $runtimeHeartbeatService->recordQueueHeartbeat('default', now()->subHours(2));
            seedPendingJob('default');

            $response = $this->getJson('/health/ready');

            assertPublicReadinessResponse($response, 503, 'not_ready');
        });
    });

    describe('CORS for health endpoints', function () {
        it('returns CORS headers for /health with whitelisted origin', function () {
            $response = $this->withHeaders([
                'Origin' => 'https://app.secpal.dev',
            ])->getJson('/health');

            $response->assertOk();
            expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
            expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        });

        it('returns CORS headers for /health/live with whitelisted origin', function () {
            $response = $this->withHeaders([
                'Origin' => 'https://app.secpal.dev',
            ])->getJson('/health/live');

            $response->assertOk();
            expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
            expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        });

        it('returns CORS headers for /health/ready with whitelisted origin', function () {
            // Setup for ready endpoint
            DB::table('tenant_keys')->insert([
                'dek_wrapped' => base64_encode(random_bytes(32)),
                'dek_nonce' => base64_encode(random_bytes(24)),
                'idx_wrapped' => base64_encode(random_bytes(32)),
                'idx_nonce' => base64_encode(random_bytes(24)),
                'key_version' => 1,
                'created_at' => now(),
            ]);
            TenantKey::generateKek();
            app(RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();

            $response = $this->withHeaders([
                'Origin' => 'https://app.secpal.dev',
            ])->getJson('/health/ready');

            $response->assertOk();
            expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
            expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        });

        it('handles OPTIONS preflight for /health/ready', function () {
            $response = $this->call('OPTIONS', '/health/ready', [], [], [], [
                'HTTP_ORIGIN' => 'https://app.secpal.dev',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]);

            $response->assertNoContent();
            expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
            expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('GET');
            expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        });

        it('handles OPTIONS preflight for /health/live', function () {
            $response = $this->call('OPTIONS', '/health/live', [], [], [], [
                'HTTP_ORIGIN' => 'https://app.secpal.dev',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]);

            $response->assertNoContent();
            expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
            expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('GET');
            expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        });

        it('handles OPTIONS preflight for /health', function () {
            $response = $this->call('OPTIONS', '/health', [], [], [], [
                'HTTP_ORIGIN' => 'https://app.secpal.dev',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]);

            $response->assertNoContent();
            expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
            expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('GET');
            expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        });
    });
});

function seedHealthReadinessPrerequisites(): void
{
    DB::table('tenant_keys')->insert([
        'dek_wrapped' => base64_encode(random_bytes(32)),
        'dek_nonce' => base64_encode(random_bytes(24)),
        'idx_wrapped' => base64_encode(random_bytes(32)),
        'idx_nonce' => base64_encode(random_bytes(24)),
        'key_version' => 1,
        'created_at' => now(),
    ]);

    TenantKey::generateKek();
}

function seedPendingJob(string $queue): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob'], JSON_THROW_ON_ERROR),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinute()->getTimestamp(),
        'created_at' => now()->subMinute()->getTimestamp(),
    ]);
}

function assertPublicReadinessResponse($response, int $statusCode, string $status): void
{
    $response->assertStatus($statusCode)
        ->assertJson([
            'status' => $status,
        ]);

    expect($response->json())->not->toHaveKey('checks')
        ->not->toHaveKey('details');
}
