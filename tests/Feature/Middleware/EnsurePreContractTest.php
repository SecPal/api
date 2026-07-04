<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Http\Middleware\EnsurePreContract;
use App\Models\Employee;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @property TenantKey $tenant
 * @property EnsurePreContract $middleware
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->middleware = new EnsurePreContract;
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('middleware allows pre contract employees', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'pre_contract',
    ]);

    $request = Request::create('/onboarding/forms', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = $this->middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('middleware blocks active employees', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $user->refresh(); // Reload user to ensure employee relationship is fresh

    $request = Request::create('/onboarding/forms', 'GET');
    $request->setUserResolver(fn () => $user);

    try {
        $this->middleware->handle($request, fn ($req) => response('OK'));
        expect(false)->toBeTrue('Expected HttpException to be thrown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

test('middleware blocks terminated employees', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'terminated',
    ]);

    $user->refresh(); // Reload user to ensure employee relationship is fresh

    $request = Request::create('/onboarding/forms', 'GET');
    $request->setUserResolver(fn () => $user);

    try {
        $this->middleware->handle($request, fn ($req) => response('OK'));
        expect(false)->toBeTrue('Expected HttpException to be thrown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

test('middleware blocks users without employee record', function (): void {
    $user = User::factory()->create();

    $request = Request::create('/onboarding/forms', 'GET');
    $request->setUserResolver(fn () => $user);

    try {
        $this->middleware->handle($request, fn ($req) => response('OK'));
        expect(false)->toBeTrue('Expected HttpException to be thrown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

test('middleware blocks applicant status', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'applicant',
    ]);

    $user->refresh(); // Reload user to ensure employee relationship is fresh

    $request = Request::create('/onboarding/forms', 'GET');
    $request->setUserResolver(fn () => $user);

    try {
        $this->middleware->handle($request, fn ($req) => response('OK'));
        expect(false)->toBeTrue('Expected HttpException to be thrown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

test('middleware blocks on_leave status', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'on_leave',
    ]);

    $user->refresh(); // Reload user to ensure employee relationship is fresh

    $request = Request::create('/onboarding/forms', 'GET');
    $request->setUserResolver(fn () => $user);

    try {
        $this->middleware->handle($request, fn ($req) => response('OK'));
        expect(false)->toBeTrue('Expected HttpException to be thrown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});
