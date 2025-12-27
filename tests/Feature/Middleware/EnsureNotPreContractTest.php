<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Middleware\EnsureNotPreContract;
use App\Models\Employee;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @property TenantKey $tenant
 * @property EnsureNotPreContract $middleware
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->middleware = new EnsureNotPreContract;
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('middleware blocks pre contract employees', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'pre_contract',
    ]);

    $user->refresh(); // Reload user to ensure employee relationship is fresh

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);

    try {
        $this->middleware->handle($request, fn ($req) => response('OK'));
        expect(false)->toBeTrue('Expected HttpException to be thrown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

test('middleware allows active employees', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $request = Request::create('/employees', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = $this->middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('middleware allows terminated employees', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'terminated',
    ]);

    $request = Request::create('/employees', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = $this->middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('middleware allows applicant status', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'applicant',
    ]);

    $request = Request::create('/employees', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = $this->middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('middleware allows on_leave status', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'on_leave',
    ]);

    $request = Request::create('/employees', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = $this->middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('middleware allows users without employee record', function (): void {
    $user = User::factory()->create();

    $request = Request::create('/employees', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = $this->middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});
