<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use App\Services\PrivateObjectService;
use App\Storage\PrivateObjectCredentials;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::factory()->create();
    $this->credential = tempnam(sys_get_temp_dir(), 'private-object-profile-');
    chmod($this->credential, 0600);
    file_put_contents($this->credential, "[api]\naws_access_key_id=SYNTHETIC_TEST_ONLY\naws_secret_access_key=".base64_encode(random_bytes(32))."\n");
    $this->stored = null;
    $this->tamper = false;
    $this->requests = [];
    config([
        'private_objects.mode' => 'managed',
        'private_objects.purpose' => 'api',
        'private_objects.credential_file' => $this->credential,
        'private_objects.replication_targets' => ['test-replica'],
        'filesystems.disks.private_objects' => [
            'driver' => 's3', 'version' => '2006-03-01', 'region' => 'eu-central-1',
            'root' => '', 'bucket' => 'test-objects', 'endpoint' => 'https://object-test.invalid',
            'use_path_style_endpoint' => true, 'throw' => true, 'retries' => 0,
            'credentials' => [PrivateObjectCredentials::class, 'load'],
            // Official SDK request serialization and response normalization.
            // Authored HTTP responses prove protocol behavior, not a provider.
            'http_handler' => function (RequestInterface $request) {
                $method = $request->getMethod();
                $this->requests[] = ['method' => $method, 'conditional' => $request->getHeaderLine('If-None-Match')];
                if ($method === 'PUT') {
                    if ($this->stored !== null) {
                        return Create::promiseFor(new Response(412));
                    }
                    $this->stored = (string) $request->getBody();

                    return Create::promiseFor(new Response(200, ['ETag' => '"not-the-canonical-hash"']));
                }
                $body = $this->tamper ? str_repeat('x', strlen($this->stored)) : $this->stored;

                return Create::promiseFor(new Response(200, [
                    'Content-Length' => (string) strlen($body),
                    'ETag' => '"not-the-canonical-hash"',
                ], $method === 'HEAD' ? '' : $body));
            },
        ],
    ]);
    Storage::forgetDisk('private_objects');
});

afterEach(function (): void {
    unlink($this->credential);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('native AWS SDK serializes conditional PUT while canonical integrity ignores provider ETag', function (): void {
    $object = app(PrivateObjectService::class)->stage($this->tenant, 'employee-document', 'ciphertext first');
    expect($this->requests[0])->toBe(['method' => 'PUT', 'conditional' => '*']);
    expect(hash('sha256', $this->stored))->toBe($object['storage_sha256']);
    expect(strlen($this->stored))->toBe($object['stored_bytes']);
    expect($this->stored)->not->toContain('ciphertext first');
    $this->assertDatabaseHas('private_objects', ['id' => $object['id'], 'state' => 'PENDING']);
});

test('same-length tampering is rejected despite a provider ETag and cannot publish READY', function (): void {
    $this->tamper = true;
    expect(fn () => app(PrivateObjectService::class)->stage($this->tenant, 'employee-document', 'integrity'))
        ->toThrow(RuntimeException::class, 'Private Object durable publication failed');
    expect(DB::table('private_objects')->where('state', 'READY')->count())->toBe(0);
    expect(DB::table('private_objects')->sole()->verified_at)->toBeNull();
});

test('explicit AWS credential authority rejects the wrong purpose and unsafe file permissions', function (): void {
    config(['private_objects.purpose' => 'worker']);
    expect(fn () => PrivateObjectCredentials::load())->toThrow(RuntimeException::class);
    config(['private_objects.purpose' => 'api']);
    file_put_contents($this->credential, file_get_contents($this->credential)."\n[worker]\naws_access_key_id=OTHER_PURPOSE\naws_secret_access_key=TEST_ONLY\n");
    expect(fn () => PrivateObjectCredentials::load())->toThrow(RuntimeException::class);
    file_put_contents($this->credential, "[api]\naws_access_key_id=TEST_ONLY\naws_secret_access_key=TEST_ONLY\n");
    chmod($this->credential, 0644);
    clearstatcache(true, $this->credential);
    expect(fn () => PrivateObjectCredentials::load())->toThrow(RuntimeException::class);
    expect($this->requests)->toBe([]);
});
