<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Middleware\CheckCustomerScope;
use App\Models\Customer;
use App\Models\CustomerUserAccess;
use App\Models\CustomerUserObjectAccess;
use App\Models\OrganizationalUnit;
use App\Models\SecPalObject;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->middleware = new CheckCustomerScope;

    // Create internal organizational structure
    $this->orgUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Branch',
        'type' => 'branch',
    ]);

    // Create customer hierarchy: Corporate -> Regional -> Local
    $this->corporateCustomer = Customer::factory()->forTenant($this->tenant->id)->corporate()->create([
        'name' => 'Corporate HQ',
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);

    $this->regionalCustomer = Customer::factory()->forTenant($this->tenant->id)->regional()->create([
        'name' => 'Regional Office',
    ]);
    $this->regionalCustomer->setParent($this->corporateCustomer);

    $this->localCustomer = Customer::factory()->forTenant($this->tenant->id)->local()->create([
        'name' => 'Local Branch',
    ]);
    $this->localCustomer->setParent($this->regionalCustomer);
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * Helper to create a mock request with an optional customer route parameter.
 */
function createCustomerMockRequest(User $user, ?string $customerId = null): Request
{
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $user);

    if ($customerId !== null) {
        $request->setRouteResolver(function () use ($customerId) {
            $route = new \Illuminate\Routing\Route('GET', '/test/{customer}', []);
            $route->parameters = ['customer' => $customerId];

            return $route;
        });
    } else {
        $request->setRouteResolver(function () {
            $route = new \Illuminate\Routing\Route('GET', '/test', []);
            $route->parameters = [];

            return $route;
        });
    }

    return $request;
}

/**
 * Helper to create a next closure that returns a success response.
 */
function createCustomerNextClosure(): Closure
{
    return fn () => new Response('OK', 200);
}

describe('CheckCustomerScope Middleware', function (): void {
    describe('for internal employees (non-Client role)', function (): void {
        beforeEach(function (): void {
            $this->internalUser = User::factory()->create();
            $this->internalUser->assignRole('Admin');
        });

        it('passes through without any checks', function (): void {
            $request = createCustomerMockRequest($this->internalUser, $this->corporateCustomer->id);
            $response = $this->middleware->handle($request, createCustomerNextClosure());

            expect($response->getStatusCode())->toBe(200);
            expect($response->getContent())->toBe('OK');
        });

        it('passes through even without customer access records', function (): void {
            $request = createCustomerMockRequest($this->internalUser);
            $response = $this->middleware->handle($request, createCustomerNextClosure());

            expect($response->getStatusCode())->toBe(200);
        });
    });

    describe('for customer users (Client role)', function (): void {
        beforeEach(function (): void {
            $this->clientUser = User::factory()->create();
            $this->clientUser->assignRole('Client');
        });

        describe('without any access records', function (): void {
            it('denies access to customer list endpoint', function (): void {
                $request = createCustomerMockRequest($this->clientUser);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(403);
                $content = json_decode($response->getContent(), true);
                expect($content['message'])->toBe('No customer access assigned');
            });

            it('denies access to specific customer endpoint', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->corporateCustomer->id);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(403);
            });
        });

        describe('with customer access records', function (): void {
            beforeEach(function (): void {
                CustomerUserAccess::factory()
                    ->forUser($this->clientUser)
                    ->forCustomer($this->corporateCustomer)
                    ->forTenant($this->tenant->id)
                    ->corporateWide()
                    ->create();
            });

            it('allows access to customer list endpoint', function (): void {
                $request = createCustomerMockRequest($this->clientUser);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(200);
            });

            it('allows access to directly assigned customer', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->corporateCustomer->id);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(200);
            });

            it('allows access to descendant customer with include_descendants=true', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->localCustomer->id);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(200);
            });

            it('sets customer on request when access granted', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->corporateCustomer->id);

                $this->middleware->handle($request, function ($req) {
                    expect($req->attributes->has('customer'))->toBeTrue();
                    expect($req->attributes->get('customer'))->toBeInstanceOf(Customer::class);
                    expect($req->attributes->get('customer')->id)->toBe($this->corporateCustomer->id);

                    return new Response('OK', 200);
                });
            });
        });

        describe('with limited customer access', function (): void {
            beforeEach(function (): void {
                // Only access to local customer without descendants
                CustomerUserAccess::factory()
                    ->forUser($this->clientUser)
                    ->forCustomer($this->localCustomer)
                    ->forTenant($this->tenant->id)
                    ->create(['include_descendants' => false]);
            });

            it('allows access to assigned customer', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->localCustomer->id);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(200);
            });

            it('denies access to ancestor customer', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->corporateCustomer->id);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(403);
                $content = json_decode($response->getContent(), true);
                expect($content['message'])->toBe('Access denied to this customer');
            });

            it('denies access to sibling customer', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->regionalCustomer->id);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(403);
            });
        });

        describe('with object-level access only', function (): void {
            beforeEach(function (): void {
                $this->object = SecPalObject::factory()
                    ->forCustomer($this->localCustomer)
                    ->forTenant($this->tenant->id)
                    ->create();

                CustomerUserObjectAccess::factory()
                    ->forUser($this->clientUser)
                    ->forObject($this->object)
                    ->forTenant($this->tenant->id)
                    ->create();
            });

            it('allows access to customer list endpoint', function (): void {
                $request = createCustomerMockRequest($this->clientUser);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(200);
            });

            it('allows access to customer with object access', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->localCustomer->id);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(200);
            });

            it('denies access to customer without object access', function (): void {
                $request = createCustomerMockRequest($this->clientUser, $this->corporateCustomer->id);
                $response = $this->middleware->handle($request, createCustomerNextClosure());

                expect($response->getStatusCode())->toBe(403);
            });
        });
    });

    describe('error handling', function (): void {
        beforeEach(function (): void {
            $this->clientUser = User::factory()->create();
            $this->clientUser->assignRole('Client');

            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->create();
        });

        it('returns 404 for invalid UUID format', function (): void {
            $request = createCustomerMockRequest($this->clientUser, 'not-a-uuid');
            $response = $this->middleware->handle($request, createCustomerNextClosure());

            expect($response->getStatusCode())->toBe(404);
            $content = json_decode($response->getContent(), true);
            expect($content['message'])->toBe('Invalid customer ID format');
        });

        it('returns 404 for non-existent customer', function (): void {
            $fakeUuid = '00000000-0000-0000-0000-000000000000';
            $request = createCustomerMockRequest($this->clientUser, $fakeUuid);
            $response = $this->middleware->handle($request, createCustomerNextClosure());

            expect($response->getStatusCode())->toBe(404);
            $content = json_decode($response->getContent(), true);
            expect($content['message'])->toBe('Customer not found');
        });

        it('returns 401 for unauthenticated request', function (): void {
            $request = Request::create('/test', 'GET');
            $request->setUserResolver(fn () => null);
            $request->setRouteResolver(function () {
                return new \Illuminate\Routing\Route('GET', '/test', []);
            });

            $response = $this->middleware->handle($request, createCustomerNextClosure());

            expect($response->getStatusCode())->toBe(401);
        });
    });

    describe('tenant isolation', function (): void {
        it('denies access to customer from different tenant', function (): void {
            $clientUser = User::factory()->create();
            $clientUser->assignRole('Client');

            // Create second tenant
            $keys2 = TenantKey::generateEnvelopeKeys();
            $tenant2 = TenantKey::create($keys2);

            $otherTenantCustomer = Customer::factory()->forTenant($tenant2->id)->create();

            // User has access in their tenant
            CustomerUserAccess::factory()
                ->forUser($clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->create();

            // Try to access other tenant's customer
            $request = createCustomerMockRequest($clientUser, $otherTenantCustomer->id);
            $response = $this->middleware->handle($request, createCustomerNextClosure());

            expect($response->getStatusCode())->toBe(403);
        });
    });
});
