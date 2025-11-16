<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Secret;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create authenticated user
    $this->user = User::factory()->create();
    actingAs($this->user, 'sanctum');
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('SecretController - List Secrets', function () {
    test('user can list own secrets', function () {
        // Arrange: Create secrets owned by user
        $ownSecret1 = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'title_plain' => 'My Password',
        ]);
        $ownSecret2 = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'title_plain' => 'API Key',
        ]);

        // Create secret by other user (should not appear)
        $otherUserKeys = TenantKey::generateEnvelopeKeys();
        $otherTenant = TenantKey::create($otherUserKeys);
        $otherUserKeys = TenantKey::generateEnvelopeKeys();
        $otherTenant = TenantKey::create($otherUserKeys);
        $otherUser = User::factory()->create();
        createTestSecret([
            'tenant_id' => $otherTenant->id,
            'owner_id' => $otherUser->id,
            'title_plain' => 'Other Secret',
        ]);

        // Act
        $response = getJson('/v1/secrets');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'expires_at',
                        'version',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                ],
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $ownSecret1->id)
            ->assertJsonPath('data.1.id', $ownSecret2->id);
    });

    test('user can filter secrets by owned status', function () {
        // Arrange: Own secret
        createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'title_plain' => 'Owned',
        ]);

        // Act
        $response = getJson('/v1/secrets?filter=owned');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    test('list secrets returns paginated results', function () {
        // Arrange: Create 15 secrets
        foreach (range(1, 15) as $i) {
            createTestSecret([
                'tenant_id' => $this->tenant->id,
                'owner_id' => $this->user->id,
                'title_plain' => "Secret {$i}",
            ]);
        }

        // Act
        $response = getJson('/v1/secrets?per_page=10');

        // Assert
        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.per_page', 10);
    });
});

describe('SecretController - Create Secret', function () {
    test('user can create secret with minimum fields', function () {
        // Arrange
        $data = [
            'title' => 'My New Secret',
        ];

        // Act
        $response = postJson('/v1/secrets', $data);

        // Assert
        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'version',
                    'created_at',
                ],
            ]);

        $secretId = $response->json('data.id');
        $secret = Secret::find($secretId);

        expect($secret)->not->toBeNull()
            ->and($secret->owner_id)->toBe($this->user->id)
            ->and($secret->tenant_id)->toBe($this->tenant->id)
            ->and($secret->title_plain)->toBe('My New Secret')
            ->and($secret->version)->toBe(1);
    });

    test('user can create secret with all fields', function () {
        // Arrange
        $data = [
            'title' => 'Complete Secret',
            'username' => 'john.doe',
            'password' => 'super-secret-123',
            'url' => 'https://secpal.app/login',
            'notes' => 'Important credentials',
            'tags' => ['work', 'production'],
            'expires_at' => '2026-12-31',
        ];

        // Act
        $response = postJson('/v1/secrets', $data);

        // Assert
        $response->assertCreated();

        $secret = Secret::find($response->json('data.id'));
        expect($secret->title_plain)->toBe('Complete Secret')
            ->and($secret->username_plain)->toBe('john.doe')
            ->and($secret->password_plain)->toBe('super-secret-123')
            ->and($secret->url_plain)->toBe('https://secpal.app/login')
            ->and($secret->notes_plain)->toBe('Important credentials')
            ->and($secret->tags)->toBe(['work', 'production'])
            ->and($secret->expires_at)->toBeInstanceOf(Carbon::class);
    });

    test('title is required when creating secret', function () {
        // Arrange
        $data = [
            'username' => 'user',
            // title missing
        ];

        // Act
        $response = postJson('/v1/secrets', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    });

    test('title must be string', function () {
        // Arrange
        $data = ['title' => 123];

        // Act
        $response = postJson('/v1/secrets', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    });

    test('expires_at must be date in future', function () {
        // Arrange
        $data = [
            'title' => 'Test',
            'expires_at' => '2020-01-01', // Past date
        ];

        // Act
        $response = postJson('/v1/secrets', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['expires_at']);
    });

    test('tags must be array of strings', function () {
        // Arrange
        $data = [
            'title' => 'Test',
            'tags' => [123, 456], // Invalid: numbers
        ];

        // Act
        $response = postJson('/v1/secrets', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tags.0', 'tags.1']);
    });
});

describe('SecretController - View Secret', function () {
    test('user can view own secret', function () {
        // Arrange
        $secret = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'title_plain' => 'My Secret',
            'username_plain' => 'admin',
            'password_plain' => 'pass123',
        ]);

        // Act
        $response = getJson("/v1/secrets/{$secret->id}");

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'username',
                    'password',
                    'url',
                    'notes',
                    'tags',
                    'expires_at',
                    'version',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.id', $secret->id)
            ->assertJsonPath('data.title', 'My Secret')
            ->assertJsonPath('data.username', 'admin')
            ->assertJsonPath('data.password', 'pass123');
    });

    test('user cannot view others secret', function () {
        // Arrange: Secret owned by different user
        $otherUserKeys = TenantKey::generateEnvelopeKeys();
        $otherTenant = TenantKey::create($otherUserKeys);
        $otherUser = User::factory()->create();
        $secret = createTestSecret([
            'tenant_id' => $otherTenant->id,
            'owner_id' => $otherUser->id,
            'title_plain' => 'Private Secret',
        ]);

        // Act
        $response = getJson("/v1/secrets/{$secret->id}");

        // Assert
        $response->assertForbidden();
    });

    test('viewing nonexistent secret returns 404', function () {
        // Arrange: Create a secret to ensure route exists
        $secret = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'title_plain' => 'Existing',
        ]);

        // Act: Try to view non-existent UUID
        $response = getJson('/v1/secrets/00000000-0000-0000-0000-000000000000');

        // Assert
        $response->assertNotFound();
    });
});

describe('SecretController - Update Secret', function () {
    test('user can update own secret', function () {
        // Arrange
        $secret = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'title_plain' => 'Original Title',
        ]);

        $updateData = [
            'title' => 'Updated Title',
            'username' => 'new-user',
        ];

        // Act
        $response = patchJson("/v1/secrets/{$secret->id}", $updateData);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.username', 'new-user')
            ->assertJsonPath('data.version', 2); // Version incremented
    });

    test('updating secret increments version', function () {
        // Arrange
        $secret = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'title_plain' => 'Original',
        ]);

        expect($secret->version)->toBe(1);

        // Act
        $response = patchJson("/v1/secrets/{$secret->id}", [
            'title' => 'Updated',
        ]);

        // Assert
        $response->assertOk();
        $secret->refresh();
        expect($secret->version)->toBe(2);
    });

    test('user cannot update others secret', function () {
        // Arrange
        $otherUserKeys = TenantKey::generateEnvelopeKeys();
        $otherTenant = TenantKey::create($otherUserKeys);
        $otherUser = User::factory()->create();
        $secret = createTestSecret([
            'tenant_id' => $otherTenant->id,
            'owner_id' => $otherUser->id,
            'title_plain' => 'Private',
        ]);

        // Act
        $response = patchJson("/v1/secrets/{$secret->id}", [
            'title' => 'Hacked',
        ]);

        // Assert
        $response->assertForbidden();
    });
});

describe('SecretController - Delete Secret', function () {
    test('user can delete own secret', function () {
        // Arrange
        $secret = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'title_plain' => 'To Delete',
        ]);

        // Act
        $response = deleteJson("/v1/secrets/{$secret->id}");

        // Assert
        $response->assertNoContent();

        // Verify soft delete
        expect(Secret::find($secret->id))->toBeNull()
            ->and(Secret::withTrashed()->find($secret->id))->not->toBeNull();
    });

    test('user cannot delete others secret', function () {
        // Arrange
        $otherUserKeys = TenantKey::generateEnvelopeKeys();
        $otherTenant = TenantKey::create($otherUserKeys);
        $otherUser = User::factory()->create();
        $secret = createTestSecret([
            'tenant_id' => $otherTenant->id,
            'owner_id' => $otherUser->id,
            'title_plain' => 'Protected',
        ]);

        // Act
        $response = deleteJson("/v1/secrets/{$secret->id}");

        // Assert
        $response->assertForbidden();

        // Verify not deleted
        expect(Secret::find($secret->id))->not->toBeNull();
    });
});
