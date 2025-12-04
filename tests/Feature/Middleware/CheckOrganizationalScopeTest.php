<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Middleware\CheckOrganizationalScope;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->user = User::factory()->create();
    $this->middleware = new CheckOrganizationalScope;

    // Create organizational hierarchy: Company -> Region -> Branch
    $this->company = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Company',
        'type' => 'company',
    ]);

    $this->region = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Region',
        'type' => 'region',
    ]);
    $this->region->setParent($this->company);

    $this->branch = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Branch',
        'type' => 'branch',
    ]);
    $this->branch->setParent($this->region);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * Helper to create a mock request with an organizational unit route parameter.
 */
function createMockRequest(User $user, ?string $unitId = null): Request
{
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $user);

    if ($unitId !== null) {
        $request->setRouteResolver(function () use ($unitId) {
            $route = new \Illuminate\Routing\Route('GET', '/test/{organizational_unit}', []);
            $route->parameters = ['organizational_unit' => $unitId];

            return $route;
        });
    }

    return $request;
}

/**
 * Helper to create a next closure that returns a success response.
 */
function createNextClosure(): Closure
{
    return fn () => new Response('OK', 200);
}

describe('CheckOrganizationalScope Middleware', function () {
    describe('with valid access', function () {
        it('allows access with direct scope and read level', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
            ]);

            $request = createMockRequest($this->user, $this->region->id);
            $response = $this->middleware->handle($request, createNextClosure(), 'read');

            expect($response->getStatusCode())->toBe(200);
            expect($response->getContent())->toBe('OK');
        });

        it('allows access with hierarchical scope (descendant)', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'write',
                'include_descendants' => true,
            ]);

            $request = createMockRequest($this->user, $this->branch->id);
            $response = $this->middleware->handle($request, createNextClosure(), 'read');

            expect($response->getStatusCode())->toBe(200);
        });

        it('allows access when access level exceeds required', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'admin',
            ]);

            $request = createMockRequest($this->user, $this->region->id);
            $response = $this->middleware->handle($request, createNextClosure(), 'read');

            expect($response->getStatusCode())->toBe(200);
        });

        it('sets organizational_unit on request when access granted', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
            ]);

            $request = createMockRequest($this->user, $this->region->id);

            $this->middleware->handle($request, function ($req) {
                expect($req->attributes->has('organizational_unit'))->toBeTrue();
                expect($req->attributes->get('organizational_unit'))->toBeInstanceOf(OrganizationalUnit::class);
                expect($req->attributes->get('organizational_unit')->id)->toBe($this->region->id);

                return new Response('OK', 200);
            }, 'read');
        });
    });

    describe('with invalid access', function () {
        it('denies access without any scope', function (): void {
            $request = createMockRequest($this->user, $this->region->id);
            $response = $this->middleware->handle($request, createNextClosure(), 'read');

            expect($response->getStatusCode())->toBe(403);
        });

        it('denies access when access level is insufficient', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
            ]);

            $request = createMockRequest($this->user, $this->region->id);
            $response = $this->middleware->handle($request, createNextClosure(), 'write');

            expect($response->getStatusCode())->toBe(403);
        });

        it('denies access to ancestor when only descendant is scoped', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->branch->id,
                'access_level' => 'admin',
            ]);

            $request = createMockRequest($this->user, $this->company->id);
            $response = $this->middleware->handle($request, createNextClosure(), 'read');

            expect($response->getStatusCode())->toBe(403);
        });

        it('denies access when include_descendants is false', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'admin',
                'include_descendants' => false,
            ]);

            $request = createMockRequest($this->user, $this->branch->id);
            $response = $this->middleware->handle($request, createNextClosure(), 'read');

            expect($response->getStatusCode())->toBe(403);
        });

        it('returns 403 with proper error message', function (): void {
            $request = createMockRequest($this->user, $this->region->id);
            $response = $this->middleware->handle($request, createNextClosure(), 'write');

            $content = json_decode($response->getContent(), true);

            expect($content)->toHaveKey('message');
            expect($content['message'])->toContain('Insufficient access level');
        });
    });

    describe('edge cases', function () {
        it('returns 404 when organizational unit does not exist', function (): void {
            $request = createMockRequest($this->user, 'non-existent-uuid');
            $response = $this->middleware->handle($request, createNextClosure(), 'read');

            expect($response->getStatusCode())->toBe(404);
        });

        it('returns 401 when user is not authenticated', function (): void {
            $request = Request::create('/test', 'GET');
            $request->setUserResolver(fn () => null);
            $request->setRouteResolver(function () {
                $route = new \Illuminate\Routing\Route('GET', '/test/{organizational_unit}', []);
                $route->parameters = ['organizational_unit' => $this->region->id];

                return $route;
            });

            $response = $this->middleware->handle($request, createNextClosure(), 'read');

            expect($response->getStatusCode())->toBe(401);
        });

        it('defaults to read access level when none specified', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
            ]);

            $request = createMockRequest($this->user, $this->region->id);
            // No access level parameter provided
            $response = $this->middleware->handle($request, createNextClosure());

            expect($response->getStatusCode())->toBe(200);
        });
    });

    describe('access level parameters', function () {
        it('accepts all valid access level parameters', function (): void {
            $levels = ['none', 'read', 'write', 'manage', 'admin'];

            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'admin', // Give highest access
            ]);

            foreach ($levels as $level) {
                $request = createMockRequest($this->user, $this->region->id);
                $response = $this->middleware->handle($request, createNextClosure(), $level);

                expect($response->getStatusCode())->toBe(200);
            }
        });
    });
});
