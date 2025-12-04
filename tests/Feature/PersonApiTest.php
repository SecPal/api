<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Permission;
use App\Models\Person;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());

    // generateKek() will create the directory if needed
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create test user with token
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    // Create global permissions (not team-scoped for this test)
    Permission::create(['name' => 'person.write', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'person.read', 'guard_name' => 'sanctum']);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('POST /v1/tenants/{tenant}/persons', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson("/v1/tenants/{$this->tenant->id}/persons", [
            'email_plain' => 'test@example.com',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks person.write permission', function (): void {
        $response = $this->withToken($this->token)
            ->postJson("/v1/tenants/{$this->tenant->id}/persons", [
                'email_plain' => 'test@example.com',
            ]);

        $response->assertStatus(403);
    });

    test('returns 422 when email_plain is missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/tenants/{$this->tenant->id}/persons", [
                'phone_plain' => '+49 123 456789',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email_plain']);
    });

    test('returns 422 when email_plain is invalid', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/tenants/{$this->tenant->id}/persons", [
                'email_plain' => 'not-an-email',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email_plain']);
    });

    test('creates Person with valid data and returns 201', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/tenants/{$this->tenant->id}/persons", [
                'email_plain' => 'test@example.com',
                'phone_plain' => '+49 123 456789',
                'note_enc' => 'Test note',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'tenant_id',
                'created_at',
                'updated_at',
            ])
            ->assertJsonFragment([
                'tenant_id' => $this->tenant->id,
            ]);

        // Verify Person was created in database
        expect(Person::where('tenant_id', $this->tenant->id)->count())->toBe(1);

        $person = Person::first();
        expect($person->email_enc)->not->toBeNull();
        expect($person->phone_enc)->not->toBeNull();
        expect($person->note_enc)->not->toBeNull();
    });

    test('does not expose encrypted fields or blind indexes in response', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/tenants/{$this->tenant->id}/persons", [
                'email_plain' => 'test@example.com',
                'phone_plain' => '+49 123 456789',
            ]);

        $response->assertStatus(201);
        $json = $response->json();

        expect($json)->not->toHaveKey('email_enc');
        expect($json)->not->toHaveKey('email_idx');
        expect($json)->not->toHaveKey('phone_enc');
        expect($json)->not->toHaveKey('phone_idx');
        expect($json)->not->toHaveKey('note_enc');
    });

    test('creates Person with only required fields', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/tenants/{$this->tenant->id}/persons", [
                'email_plain' => 'minimal@example.com',
            ]);

        $response->assertStatus(201);

        $person = Person::where('tenant_id', $this->tenant->id)->first();
        expect($person->email_enc)->not->toBeNull();
        expect($person->phone_enc)->toBeNull();
        expect($person->note_enc)->toBeNull();
    });

    test('returns 404 when tenant does not exist', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.write');
        $nonExistentTenantId = 99999;

        $response = $this->withToken($this->token)
            ->postJson("/v1/tenants/{$nonExistentTenantId}/persons", [
                'email_plain' => 'test@example.com',
            ]);

        $response->assertStatus(404);
    });
});

describe('GET /v1/tenants/{tenant}/persons/by-email', function () {
    beforeEach(function (): void {
        // Create a test Person
        $this->testPerson = new Person;
        $this->testPerson->tenant_id = $this->tenant->id;
        $this->testPerson->email_plain = 'search@example.com';
        $this->testPerson->phone_plain = '+49 123 456789';
        $this->testPerson->note_enc = 'Test note';
        $this->testPerson->save();
    });

    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/v1/tenants/{$this->tenant->id}/persons/by-email?email=search@example.com");

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks person.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson("/v1/tenants/{$this->tenant->id}/persons/by-email?email=search@example.com");

        $response->assertStatus(403);
    });

    test('`GET /v1/tenants/{tenant}/persons/by-email` → returns 400 when email query parameter is missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.read');

        $response = $this->withToken($this->token)
            ->getJson("/v1/tenants/{$this->tenant->id}/persons/by-email");

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Email query parameter is required',
            ]);
    });

    test('returns 404 when Person not found', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.read');

        $response = $this->withToken($this->token)
            ->getJson("/v1/tenants/{$this->tenant->id}/persons/by-email?email=notfound@example.com");

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Person not found',
            ]);
    });

    test('finds Person by email and returns 200', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.read');

        $response = $this->withToken($this->token)
            ->getJson("/v1/tenants/{$this->tenant->id}/persons/by-email?email=search@example.com");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'tenant_id',
                'created_at',
                'updated_at',
            ])
            ->assertJsonFragment([
                'id' => $this->testPerson->id,
                'tenant_id' => $this->tenant->id,
            ]);
    });

    test('search is case-insensitive', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.read');

        $response = $this->withToken($this->token)
            ->getJson("/v1/tenants/{$this->tenant->id}/persons/by-email?email=SEARCH@EXAMPLE.COM");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $this->testPerson->id,
            ]);
    });

    test('does not expose encrypted fields or blind indexes in response', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'person.read');

        $response = $this->withToken($this->token)
            ->getJson("/v1/tenants/{$this->tenant->id}/persons/by-email?email=search@example.com");

        $response->assertStatus(200);
        $json = $response->json();

        expect($json)->not->toHaveKey('email_enc');
        expect($json)->not->toHaveKey('email_idx');
        expect($json)->not->toHaveKey('phone_enc');
        expect($json)->not->toHaveKey('phone_idx');
        expect($json)->not->toHaveKey('note_enc');
    });

    test('enforces tenant isolation', function (): void {
        // Create another tenant and person
        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        $person2 = new Person;
        $person2->tenant_id = $tenant2->id;
        $person2->email_plain = 'other@example.com';
        $person2->save();

        givePermissionWithTenant($this->user, $this->tenant->id, 'person.read');

        // Try to find tenant2's person using tenant1's endpoint
        $response = $this->withToken($this->token)
            ->getJson("/v1/tenants/{$this->tenant->id}/persons/by-email?email=other@example.com");

        $response->assertStatus(404);
    });
});
