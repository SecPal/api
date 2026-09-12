<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->user->createToken('transactional-edit-test')->plainTextToken;
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

    $this->legalEntity = LegalEntity::factory()
        ->forTenant((string) $this->tenant->id)
        ->create();
    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'name' => 'Original Customer',
    ]);
    $this->establishments = Establishment::factory()->count(3)->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/** @return array{customer: array<string, mixed>, customer_establishments: list<array<string, mixed>>} */
function transactionalCustomerPayload(
    Customer $customer,
    array $customerChanges = [],
    array $establishments = [],
): array {
    return [
        'customer' => $customerChanges,
        'customer_establishments' => $establishments,
    ];
}

function customerEtag(object $test, Customer $customer, ?string $token = null): string
{
    $response = $test->withToken($token ?? $test->token)
        ->getJson("/v1/customers/{$customer->id}")
        ->assertOk();

    $etag = $response->headers->get('ETag');
    expect($etag)->toBeString()->toMatch('/^"[^"]+"$/');

    return $etag;
}

function rawTransactionalPut(object $test, string $url, string $etag, string $content): Illuminate\Testing\TestResponse
{
    return $test->call('PUT', $url, [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$test->token,
        'HTTP_IF_MATCH' => $etag,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $content);
}

test('transactional customer edit requires authentication', function (): void {
    $this->putJson("/v1/customers/{$this->customer->id}/transactional-edit", [
        'customer' => [],
        'customer_establishments' => [],
    ])->assertUnauthorized();
});

test('transactionally updates customer and replaces establishment membership with the closed projection', function (): void {
    $retained = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishments[0]->id,
        'contact_name_plain' => 'Preserved',
        'phone_plain' => 'old',
    ]);
    $removed = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishments[1]->id,
    ]);

    $response = $this->withToken($this->token)
        ->withHeader('If-Match', customerEtag($this, $this->customer))
        ->putJson("/v1/customers/{$this->customer->id}/transactional-edit", transactionalCustomerPayload(
            $this->customer,
            ['name' => 'Updated Customer'],
            [
                [
                    'customer_id' => $this->customer->id,
                    'establishment_id' => $this->establishments[0]->id,
                    'phone' => null,
                ],
                [
                    'customer_id' => $this->customer->id,
                    'establishment_id' => $this->establishments[2]->id,
                    'email' => 'new@example.com',
                ],
            ],
        ));

    $response->assertOk()
        ->assertHeaderMissing('ETag')
        ->assertJsonPath('data.name', 'Updated Customer')
        ->assertJsonPath('data.customer_establishments.0.contact_name', 'Preserved')
        ->assertJsonPath('data.customer_establishments.0.phone', null)
        ->assertJsonMissingPath('data.sites')
        ->assertJsonMissingPath('data.assignments')
        ->assertJsonMissingPath('data.sites_count');

    expect($retained->refresh()->contact_name)->toBe('Preserved')
        ->and($retained->phone)->toBeNull()
        ->and($removed->fresh()?->trashed())->toBeTrue();

    $created = CustomerEstablishment::query()
        ->where('customer_id', $this->customer->id)
        ->where('establishment_id', $this->establishments[2]->id)
        ->firstOrFail();
    expect($created->contact_name)->toBeNull()
        ->and($created->email)->toBe('new@example.com');
});

test('operation authorization precedes customer lookup and is closed', function (): void {
    $unauthorized = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $token = $unauthorized->createToken('unauthorized')->plainTextToken;
    $missing = fake()->uuid();
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);

    foreach ([$this->customer->id, $missing, $otherCustomer->id] as $customerId) {
        $this->withToken($token)
            ->withHeader('If-Match', '"anything"')
            ->putJson("/v1/customers/{$customerId}/transactional-edit", transactionalCustomerPayload($this->customer))
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ]);
    }

    $scope = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    UserInternalOrganizationalScope::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'organizational_unit_id' => $scope->id,
        'access_level' => 'manage',
    ]);

    $this->withToken($this->token)
        ->withHeader('If-Match', '"anything"')
        ->putJson("/v1/customers/{$this->customer->id}/transactional-edit", transactionalCustomerPayload($this->customer))
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'Insufficient permissions',
            'code' => 'FORBIDDEN',
        ]);
});

test('authorized missing and tenant-inaccessible customers share the closed response', function (): void {
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);

    foreach ([fake()->uuid(), $otherCustomer->id] as $customerId) {
        $this->withToken($this->token)
            ->withHeader('If-Match', '"anything"')
            ->putJson("/v1/customers/{$customerId}/transactional-edit", transactionalCustomerPayload(
                $this->customer,
                ['name' => 'Ignored'],
            ))
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found',
                'code' => 'NOT_FOUND',
            ]);
    }
});

test('rejects structural, unknown-property, duplicate, and path-identity failures neutrally', function (): void {
    $etag = customerEtag($this, $this->customer);
    $url = "/v1/customers/{$this->customer->id}/transactional-edit";

    $this->withToken($this->token)->withHeader('If-Match', $etag)
        ->putJson($url, [
            'customer' => ['name' => 'Valid'],
            'customer_establishments' => [],
            'internal_flag' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.request.0', 'The request body is invalid.');

    $this->withToken($this->token)->withHeader('If-Match', $etag)
        ->putJson($url, [
            'customer' => ['unknown' => 'secret'],
            'customer_establishments' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.customer.0', 'The customer payload is invalid.')
        ->assertJsonMissingPath('errors.unknown');

    $this->withToken($this->token)->withHeader('If-Match', $etag)
        ->putJson($url, [
            'customer' => [
                'billing_address' => [
                    'street' => 'Hauptstrasse 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                    'internal_account_id' => 'must-not-be-accepted',
                ],
            ],
            'customer_establishments' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.customer.0', 'The customer payload is invalid.')
        ->assertJsonMissingPath('errors.internal_account_id');

    $this->withToken($this->token)->withHeader('If-Match', $etag)
        ->putJson($url, transactionalCustomerPayload(
            $this->customer,
            ['name' => 'Valid'],
            [
                [
                    'customer_id' => $this->customer->id,
                    'establishment_id' => $this->establishments[0]->id,
                    'contact_name' => 'First',
                ],
                [
                    'customer_id' => $this->customer->id,
                    'establishment_id' => $this->establishments[0]->id,
                    'contact_name' => 'Different',
                ],
            ],
        ))
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.customer_establishments.0',
            'Each establishment may be assigned at most once.',
        );

    $identityResponse = $this->withToken($this->token)->withHeader('If-Match', $etag)
        ->putJson($url, transactionalCustomerPayload(
            $this->customer,
            ['name' => 'Valid'],
            [[
                'customer_id' => fake()->uuid(),
                'establishment_id' => $this->establishments[0]->id,
            ]],
        ))
        ->assertUnprocessable();

    expect($identityResponse->json('errors')['customer_establishments.0.customer_id'])
        ->toBe(['The selected customer is invalid.']);
});

test('rejects every ineligible desired establishment without disclosing why', function (): void {
    $otherLegalEntity = LegalEntity::factory()
        ->forTenant((string) $this->tenant->id)
        ->create();
    $wrongLegalEntity = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $otherLegalEntity->id,
    ]);
    $inactive = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'is_active' => false,
    ]);
    $deleted = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $deleted->delete();
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $otherEstablishment = Establishment::factory()->forTenant($otherTenant->id)->create();

    foreach ([fake()->uuid(), $wrongLegalEntity->id, $inactive->id, $deleted->id, $otherEstablishment->id] as $id) {
        $response = $this->withToken($this->token)
            ->withHeader('If-Match', customerEtag($this, $this->customer))
            ->putJson(
                "/v1/customers/{$this->customer->id}/transactional-edit",
                transactionalCustomerPayload(
                    $this->customer,
                    ['name' => 'Valid'],
                    [[
                        'customer_id' => $this->customer->id,
                        'establishment_id' => $id,
                    ]],
                ),
            )
            ->assertUnprocessable();

        expect($response->json('errors')['customer_establishments.0.establishment_id'])
            ->toBe(['The selected establishment is invalid.']);
    }
});

test('applies If-Match before request validation and dependency conflicts', function (): void {
    CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishments[0]->id,
    ]);
    Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishments[0]->id,
    ]);
    $newLegalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $stale = customerEtag($this, $this->customer);
    $this->customer->update(['name' => 'Concurrent Change']);
    $url = "/v1/customers/{$this->customer->id}/transactional-edit";

    foreach ([
        '{"customer":',
        json_encode(transactionalCustomerPayload(
            $this->customer,
            ['name' => null, 'legal_entity_id' => $newLegalEntity->id],
        ), JSON_THROW_ON_ERROR),
        json_encode(transactionalCustomerPayload(
            $this->customer,
            ['name' => 'Valid', 'legal_entity_id' => $newLegalEntity->id],
        ), JSON_THROW_ON_ERROR),
    ] as $content) {
        rawTransactionalPut($this, $url, $stale, $content)
            ->assertStatus(412)
            ->assertExactJson([
                'message' => 'The customer edit snapshot is stale.',
                'code' => 'CUSTOMER_EDIT_STALE',
            ]);
    }

    rawTransactionalPut($this, $url, 'W/"weak"', '{"customer":')
        ->assertBadRequest()
        ->assertExactJson([
            'message' => 'Invalid request parameters',
            'code' => 'BAD_REQUEST',
        ]);

    $current = customerEtag($this, $this->customer);
    rawTransactionalPut($this, $url, $current, '{"customer":')
        ->assertBadRequest()
        ->assertExactJson([
            'message' => 'Invalid request parameters',
            'code' => 'BAD_REQUEST',
        ]);

    $this->withToken($this->token)->withHeader('If-Match', $current)
        ->putJson($url, transactionalCustomerPayload(
            $this->customer,
            ['name' => null, 'legal_entity_id' => $newLegalEntity->id],
        ))
        ->assertUnprocessable();

    $this->withToken($this->token)->withHeader('If-Match', $current)
        ->putJson($url, transactionalCustomerPayload(
            $this->customer,
            ['name' => 'Valid', 'legal_entity_id' => $newLegalEntity->id],
        ))
        ->assertConflict()
        ->assertExactJson([
            'message' => 'The customer edit conflicts with the current resource state.',
            'code' => 'CUSTOMER_EDIT_CONFLICT',
        ]);
});

test('blocks omission of a site-dependent relationship and rolls back customer changes', function (): void {
    CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishments[0]->id,
    ]);
    Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishments[0]->id,
    ]);

    $this->withToken($this->token)
        ->withHeader('If-Match', customerEtag($this, $this->customer))
        ->putJson(
            "/v1/customers/{$this->customer->id}/transactional-edit",
            transactionalCustomerPayload($this->customer, ['name' => 'Must Roll Back']),
        )
        ->assertConflict();

    expect($this->customer->refresh()->name)->toBe('Original Customer');
});

test('strong GET validator is stable and covers every emitted aggregate relationship family', function (): void {
    $etag = customerEtag($this, $this->customer);
    expect(customerEtag($this, $this->customer))->toBe($etag);

    $this->customer->update(['name' => 'Changed']);
    $afterCustomer = customerEtag($this, $this->customer);

    $link = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishments[0]->id,
    ]);
    $afterLink = customerEtag($this, $this->customer);

    Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $link->establishment_id,
    ]);
    $afterSite = customerEtag($this, $this->customer);

    $assignedUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $assignment = $this->customer->assignments()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $assignedUser->id,
        'role' => 'Contact',
    ]);
    $afterAssignment = customerEtag($this, $this->customer);

    $assignedUser->update(['name' => 'Representation changed']);
    $afterUser = customerEtag($this, $this->customer);

    expect([$etag, $afterCustomer, $afterLink, $afterSite, $afterAssignment, $afterUser])
        ->each->toMatch('/^"[^"]+"$/')
        ->and(array_unique([$etag, $afterCustomer, $afterLink, $afterSite, $afterAssignment, $afterUser]))
        ->toHaveCount(6)
        ->and($assignment->exists)->toBeTrue();
});

test('stale retry needs a fresh GET and successful PUT emits no successor validator', function (): void {
    $url = "/v1/customers/{$this->customer->id}/transactional-edit";
    $firstEtag = customerEtag($this, $this->customer);
    $payload = transactionalCustomerPayload($this->customer, ['name' => 'First Edit']);

    $this->withToken($this->token)
        ->withHeader('If-Match', $firstEtag)
        ->putJson($url, $payload)
        ->assertOk()
        ->assertHeaderMissing('ETag');

    $this->withToken($this->token)
        ->withHeader('If-Match', $firstEtag)
        ->putJson($url, transactionalCustomerPayload($this->customer, ['name' => 'Stale Edit']))
        ->assertStatus(412);

    $freshEtag = customerEtag($this, $this->customer);
    expect($freshEtag)->not->toBe($firstEtag);

    $this->withToken($this->token)
        ->withHeader('If-Match', $freshEtag)
        ->putJson($url, transactionalCustomerPayload($this->customer, ['name' => 'Retry Edit']))
        ->assertOk()
        ->assertJsonPath('data.name', 'Retry Edit');
});

test('customer write failure rolls back relationship reconciliation', function (): void {
    $link = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishments[0]->id,
    ]);
    $duplicate = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'name' => 'Duplicate GmbH',
        'billing_address' => [
            'street' => 'Same 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ],
    ]);

    $this->withToken($this->token)
        ->withHeader('If-Match', customerEtag($this, $this->customer))
        ->putJson(
            "/v1/customers/{$this->customer->id}/transactional-edit",
            transactionalCustomerPayload($this->customer, [
                'name' => $duplicate->name,
                'billing_address' => $duplicate->billing_address,
            ]),
        )
        ->assertConflict();

    expect($link->fresh()?->trashed())->toBeFalse()
        ->and($this->customer->refresh()->name)->toBe('Original Customer');
});

test('relationship write failure rolls back customer master data', function (): void {
    $targetId = $this->establishments[0]->id;
    DB::unprepared(<<<SQL
        CREATE FUNCTION reject_transactional_relationship_test()
        RETURNS trigger
        LANGUAGE plpgsql
        AS \$\$
        BEGIN
            IF NEW.establishment_id = '{$targetId}'::uuid THEN
                RAISE EXCEPTION 'injected relationship failure';
            END IF;
            RETURN NEW;
        END
        \$\$;
        CREATE TRIGGER reject_transactional_relationship_test
        BEFORE INSERT ON customer_establishments
        FOR EACH ROW EXECUTE FUNCTION reject_transactional_relationship_test();
        SQL);

    try {
        $this->withoutExceptionHandling();

        expect(fn () => $this->withToken($this->token)
            ->withHeader('If-Match', customerEtag($this, $this->customer))
            ->putJson(
                "/v1/customers/{$this->customer->id}/transactional-edit",
                transactionalCustomerPayload(
                    $this->customer,
                    ['name' => 'Must Roll Back'],
                    [[
                        'customer_id' => $this->customer->id,
                        'establishment_id' => $targetId,
                    ]],
                ),
            ))->toThrow(Illuminate\Database\QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS reject_transactional_relationship_test ON customer_establishments');
        DB::unprepared('DROP FUNCTION IF EXISTS reject_transactional_relationship_test()');
    }

    expect($this->customer->refresh()->name)->toBe('Original Customer')
        ->and(CustomerEstablishment::query()
            ->where('customer_id', $this->customer->id)
            ->exists())->toBeFalse();
});

test('reconciles an existing membership swap without partial or duplicate state', function (): void {
    foreach ($this->establishments->take(2) as $establishment) {
        CustomerEstablishment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'customer_id' => $this->customer->id,
            'establishment_id' => $establishment->id,
        ]);
    }

    $desired = [
        [
            'customer_id' => $this->customer->id,
            'establishment_id' => $this->establishments[1]->id,
            'contact_name' => 'Second',
        ],
        [
            'customer_id' => $this->customer->id,
            'establishment_id' => $this->establishments[2]->id,
            'contact_name' => 'Third',
        ],
    ];

    $this->withToken($this->token)
        ->withHeader('If-Match', customerEtag($this, $this->customer))
        ->putJson(
            "/v1/customers/{$this->customer->id}/transactional-edit",
            transactionalCustomerPayload($this->customer, ['name' => 'Swapped'], $desired),
        )
        ->assertOk();

    expect(CustomerEstablishment::query()
        ->where('customer_id', $this->customer->id)
        ->pluck('establishment_id')
        ->sort()
        ->values()
        ->all())->toBe(collect([$this->establishments[1]->id, $this->establishments[2]->id])
        ->sort()
        ->values()
        ->all());
});

test('authorization drift inside the aggregate transaction aborts and rolls back', function (): void {
    $permissionId = (int) DB::table('permissions')
        ->where('name', 'customers.update')
        ->value('id');
    $userId = $this->user->id;
    $tenantId = $this->tenant->id;

    DB::unprepared(<<<SQL
        CREATE FUNCTION revoke_transactional_permission_test()
        RETURNS trigger
        LANGUAGE plpgsql
        AS \$\$
        BEGIN
            DELETE FROM model_has_permissions
            WHERE permission_id = {$permissionId}
              AND model_type = 'App\\Models\\User'
              AND model_id = '{$userId}'
              AND tenant_id = {$tenantId};
            RETURN NEW;
        END
        \$\$;
        CREATE TRIGGER revoke_transactional_permission_test
        AFTER UPDATE ON customers
        FOR EACH ROW EXECUTE FUNCTION revoke_transactional_permission_test();
        SQL);

    try {
        $this->withToken($this->token)
            ->withHeader('If-Match', customerEtag($this, $this->customer))
            ->putJson(
                "/v1/customers/{$this->customer->id}/transactional-edit",
                transactionalCustomerPayload($this->customer, ['name' => 'Must Roll Back']),
            )
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ]);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS revoke_transactional_permission_test ON customers');
        DB::unprepared('DROP FUNCTION IF EXISTS revoke_transactional_permission_test()');
    }

    expect($this->customer->refresh()->name)->toBe('Original Customer')
        ->and($this->user->refresh()->can('customers.update'))->toBeTrue();
});
