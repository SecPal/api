<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\SecretShare;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/**
 * @property TenantKey $tenant
 */
uses(RefreshDatabase::class)->group('integration', 'secret-sharing');

beforeEach(function (): void {
    // Set up tenant keys for encryption
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

describe('Secrets + Shares Integration', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->sharedUser = User::factory()->create();
        $this->secret = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->owner->id,
            'title_plain' => 'Shared Secret',
        ]);
    });

    test('user with read share can view secret', function () {
        // Grant read permission
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // Can view
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}");

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'id' => $this->secret->id,
                'title' => 'Shared Secret',
            ],
        ]);
    });

    test('user with read permission cannot update secret', function () {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->patchJson("/v1/secrets/{$this->secret->id}", [
                'title_plain' => 'Updated Title',
            ]);

        $response->assertForbidden();
    });

    test('user with write share can update secret', function () {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'write',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->patchJson("/v1/secrets/{$this->secret->id}", [
                'title_plain' => 'Updated by Shared User',
            ]);

        // User with write permission can perform update (not forbidden)
        $response->assertOk();
    });

    test('user with write permission cannot delete secret', function () {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'write',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->deleteJson("/v1/secrets/{$this->secret->id}");

        $response->assertForbidden();
    });

    test('user with admin share can delete secret', function () {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'admin',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->deleteJson("/v1/secrets/{$this->secret->id}");

        $response->assertNoContent();
        expect($this->secret->fresh()->trashed())->toBeTrue();
    });

    test('expired share does not grant access', function () {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now()->subDays(10),
            'expires_at' => now()->subDay(), // Expired yesterday
        ]);

        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}");

        $response->assertForbidden();
    });

    test('revoking share immediately removes access', function () {
        $share = SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // User can access
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}");
        $response->assertOk();

        // Owner revokes access
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/v1/secrets/{$this->secret->id}/shares/{$share->id}");

        // User can no longer access
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}");
        $response->assertForbidden();
    });
});

describe('Secrets + Attachments + Shares Integration', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->sharedUser = User::factory()->create();
        $this->secret = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->owner->id,
            'title_plain' => 'Secret with Files',
        ]);
    });

    test('user with read share can view attachments', function () {
        // Owner uploads attachment
        $file = UploadedFile::fake()->create('document.pdf', 100);
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/v1/secrets/{$this->secret->id}/attachments", [
                'file' => $file,
            ]);
        $response->assertCreated();
        $attachmentId = $response->json('data.id');

        // Grant read permission to shared user
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // Shared user can view attachments list
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}/attachments");
        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        // Shared user can download attachment
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->get("/v1/attachments/{$attachmentId}/download");
        $response->assertOk();
    });

    test('user with read share cannot upload attachments', function () {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        $file = UploadedFile::fake()->create('document.pdf', 100);
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->postJson("/v1/secrets/{$this->secret->id}/attachments", [
                'file' => $file,
            ]);

        $response->assertForbidden();
    });

    test('user with write share can upload attachments', function () {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'write',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 100);
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->postJson("/v1/secrets/{$this->secret->id}/attachments", [
                'file' => $file,
            ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => ['id', 'filename', 'file_size', 'mime_type'],
        ]);
    });

    test('user with write share can delete attachments', function () {
        // Owner uploads attachment
        $file = UploadedFile::fake()->create('temp.pdf', 100);
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/v1/secrets/{$this->secret->id}/attachments", [
                'file' => $file,
            ]);
        $attachmentId = $response->json('data.id');

        // Grant write permission
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->sharedUser->id,
            'permission' => 'write',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // Shared user can delete
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->deleteJson("/v1/attachments/{$attachmentId}");

        $response->assertNoContent();
        expect(SecretAttachment::find($attachmentId))->toBeNull();
    });

    test('user without share cannot access attachments', function () {
        // Owner uploads attachment
        $file = UploadedFile::fake()->create('private.pdf', 100);
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/v1/secrets/{$this->secret->id}/attachments", [
                'file' => $file,
            ]);
        $attachmentId = $response->json('data.id');

        // Shared user has NO share
        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}/attachments");
        $response->assertForbidden();

        $response = $this->actingAs($this->sharedUser, 'sanctum')
            ->get("/v1/attachments/{$attachmentId}/download");
        $response->assertForbidden();
    });
});

describe('Role-Based Shares Integration', function () {
    beforeEach(function () {
        // Set up permission registrar for tenant context
        $registrar = app(Spatie\Permission\PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->tenant->id);

        $this->owner = User::factory()->create();
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $this->secret = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->owner->id,
            'title_plain' => 'Team Secret',
        ]);
    });

    test('user with role receives permissions from role share', function () {
        // Create role and assign to userA
        $role = Role::create(['name' => 'Team Manager', 'guard_name' => 'sanctum']);
        $this->userA->assignRole($role);

        // Share secret with role
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'role_id' => $role->id,
            'permission' => 'write',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // UserA can access via role
        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}");
        $response->assertOk();

        // UserA can update via role (write permission)
        $response = $this->actingAs($this->userA, 'sanctum')
            ->patchJson("/v1/secrets/{$this->secret->id}", [
                'title_plain' => 'Updated by Team Manager',
            ]);
        $response->assertOk();
    });

    test('user removed from role loses access to role-shared secrets', function () {
        $role = Role::create(['name' => 'Reviewers', 'guard_name' => 'sanctum']);
        $this->userA->assignRole($role);

        SecretShare::create([
            'secret_id' => $this->secret->id,
            'role_id' => $role->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // UserA can access
        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}");
        $response->assertOk();

        // Remove role
        $this->userA->removeRole($role);

        // UserA can no longer access
        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/v1/secrets/{$this->secret->id}");
        $response->assertForbidden();
    });

    test('multiple roles combine permissions correctly', function () {
        $readRole = Role::create(['name' => 'Viewers', 'guard_name' => 'sanctum']);
        $writeRole = Role::create(['name' => 'Editors', 'guard_name' => 'sanctum']);

        // UserA has both roles
        $this->userA->assignRole([$readRole, $writeRole]);

        // Secret shared with read role
        $secret1 = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->owner->id,
            'title_plain' => 'Read Only Secret',
        ]);
        SecretShare::create([
            'secret_id' => $secret1->id,
            'role_id' => $readRole->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // Secret shared with write role
        $secret2 = createTestSecret([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->owner->id,
            'title_plain' => 'Editable Secret',
        ]);
        SecretShare::create([
            'secret_id' => $secret2->id,
            'role_id' => $writeRole->id,
            'permission' => 'write',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // UserA can view secret1 (read role)
        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/v1/secrets/{$secret1->id}");
        $response->assertOk();

        // UserA can edit secret2 (write role)
        $response = $this->actingAs($this->userA, 'sanctum')
            ->patchJson("/v1/secrets/{$secret2->id}", [
                'title_plain' => 'Modified',
            ]);
        $response->assertOk();
    });
});

describe('Edge Cases', function () {
    test('cascade delete: force deleting secret removes shares', function () {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $owner = User::factory()->create();
        $sharedUser = User::factory()->create();
        $secret = createTestSecret([
            'tenant_id' => $tenant->id,
            'owner_id' => $owner->id,
        ]);

        $share = SecretShare::create([
            'secret_id' => $secret->id,
            'user_id' => $sharedUser->id,
            'permission' => 'read',
            'granted_by' => $owner->id,
            'granted_at' => now(),
        ]);

        // Force delete secret (bypass soft delete)
        $secret->forceDelete();

        // Share is also deleted (cascade)
        expect(SecretShare::find($share->id))->toBeNull();
    });

    test('cascade delete: force deleting secret removes attachments', function () {
        Storage::fake('local');
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $owner = User::factory()->create();
        $secret = createTestSecret([
            'tenant_id' => $tenant->id,
            'owner_id' => $owner->id,
        ]);

        // Upload attachment
        $file = UploadedFile::fake()->create('file.pdf', 100);
        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/v1/secrets/{$secret->id}/attachments", [
                'file' => $file,
            ]);
        $attachmentId = $response->json('data.id');

        // Force delete secret (bypass soft delete)
        $secret->forceDelete();

        // Attachment is also deleted
        expect(SecretAttachment::find($attachmentId))->toBeNull();
    });

    test('cascade delete: deleting user removes their shares', function () {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $owner = User::factory()->create();
        $sharedUser = User::factory()->create();
        $secret = createTestSecret([
            'tenant_id' => $tenant->id,
            'owner_id' => $owner->id,
        ]);

        $share = SecretShare::create([
            'secret_id' => $secret->id,
            'user_id' => $sharedUser->id,
            'permission' => 'read',
            'granted_by' => $owner->id,
            'granted_at' => now(),
        ]);

        // Delete shared user
        $sharedUser->delete();

        // Share is also deleted
        expect(SecretShare::find($share->id))->toBeNull();
    });

    test('owner can always access secret even with no explicit share', function () {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $owner = User::factory()->create();
        $secret = createTestSecret([
            'tenant_id' => $tenant->id,
            'owner_id' => $owner->id,
        ]);

        // No SecretShare records exist
        expect($secret->shares()->count())->toBe(0);

        // Owner can still access
        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/v1/secrets/{$secret->id}");
        $response->assertOk();

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/v1/secrets/{$secret->id}", [
                'title_plain' => 'Owner Update',
            ]);
        $response->assertOk();
    });

    test('sharing with self is allowed but redundant', function () {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $owner = User::factory()->create();
        $secret = createTestSecret([
            'tenant_id' => $tenant->id,
            'owner_id' => $owner->id,
        ]);

        // Owner shares with themselves
        $share = SecretShare::create([
            'secret_id' => $secret->id,
            'user_id' => $owner->id,
            'permission' => 'read',
            'granted_by' => $owner->id,
            'granted_at' => now(),
        ]);

        expect($share)->toBeInstanceOf(SecretShare::class);

        // Owner still has full access (not limited by share)
        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/v1/secrets/{$secret->id}");
        $response->assertNoContent();
    });
});
