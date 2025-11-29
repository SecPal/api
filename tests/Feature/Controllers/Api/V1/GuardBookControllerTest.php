<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\GuardBook;
use App\Models\GuardBookReport;
use App\Models\ObjectArea;
use App\Models\OrganizationalUnit;
use App\Models\SecPalObject;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

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

    // Set tenant context for permission system
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    // Create authenticated user with admin role
    $this->user = User::factory()->create();
    $this->user->assignRole('Admin');

    actingAs($this->user, 'sanctum');

    // Create organizational unit
    $this->orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    // Give user admin scope
    UserInternalOrganizationalScope::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'admin',
    ]);

    // Create customer
    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);

    // Create object
    $this->object = SecPalObject::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
    ]);

    // Create guard book
    $this->guardBook = GuardBook::factory()->create([
        'tenant_id' => $this->tenant->id,
        'object_id' => $this->object->id,
        'title' => 'Main Guard Book',
    ]);
});

afterEach(function () {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GuardBookController - List', function () {
    test('user can list guard books', function () {
        // Arrange
        GuardBook::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $this->object->id,
            'title' => 'Secondary Guard Book',
        ]);

        // Act
        $response = getJson('/v1/guard-books');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'is_active', 'created_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonCount(2, 'data');
    });

    test('list guard books can filter by object_id', function () {
        // Arrange: Create another object and guard book
        $otherObject = SecPalObject::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);
        GuardBook::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $otherObject->id,
        ]);

        // Act
        $response = getJson("/v1/guard-books?object_id={$this->object->id}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->guardBook->id);
    });

    test('list guard books can filter by is_active', function () {
        // Arrange
        GuardBook::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $this->object->id,
            'is_active' => false,
        ]);

        // Act
        $response = getJson('/v1/guard-books?is_active=true');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', true);
    });
});

describe('GuardBookController - Create', function () {
    test('user can create guard book for object', function () {
        // Arrange
        $data = [
            'object_id' => $this->object->id,
            'title' => 'New Guard Book',
            'description' => 'Description of new guard book',
        ];

        // Act
        $response = postJson('/v1/guard-books', $data);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.title', 'New Guard Book')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('guard_books', [
            'title' => 'New Guard Book',
            'object_id' => $this->object->id,
        ]);
    });

    test('user can create guard book for object area', function () {
        // Arrange
        $area = ObjectArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $this->object->id,
            'requires_separate_guard_book' => true,
        ]);

        $data = [
            'object_area_id' => $area->id,
            'title' => 'Area Guard Book',
        ];

        // Act
        $response = postJson('/v1/guard-books', $data);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.title', 'Area Guard Book')
            ->assertJsonPath('data.is_area_specific', true);

        $this->assertDatabaseHas('guard_books', [
            'title' => 'Area Guard Book',
            'object_area_id' => $area->id,
            'object_id' => null,
        ]);
    });

    test('create guard book fails with both object_id and object_area_id', function () {
        // Arrange
        $area = ObjectArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $this->object->id,
        ]);

        $data = [
            'object_id' => $this->object->id,
            'object_area_id' => $area->id,
            'title' => 'Invalid Guard Book',
        ];

        // Act
        $response = postJson('/v1/guard-books', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['object_id']);
    });

    test('create guard book fails without object_id or object_area_id', function () {
        // Arrange
        $data = [
            'title' => 'Orphan Guard Book',
        ];

        // Act
        $response = postJson('/v1/guard-books', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['object_id']);
    });
});

describe('GuardBookController - Show', function () {
    test('user can view guard book', function () {
        // Act
        $response = getJson("/v1/guard-books/{$this->guardBook->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $this->guardBook->id)
            ->assertJsonPath('data.title', 'Main Guard Book');
    });
});

describe('GuardBookController - Update', function () {
    test('user can update guard book', function () {
        // Arrange
        $data = [
            'title' => 'Updated Title',
            'description' => 'Updated description',
        ];

        // Act
        $response = patchJson("/v1/guard-books/{$this->guardBook->id}", $data);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.description', 'Updated description');
    });

    test('user can archive guard book', function () {
        // Arrange
        $data = ['is_active' => false];

        // Act
        $response = patchJson("/v1/guard-books/{$this->guardBook->id}", $data);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('guard_books', [
            'id' => $this->guardBook->id,
            'is_active' => false,
        ]);
    });
});

describe('GuardBookController - Delete', function () {
    test('user can delete guard book', function () {
        // Arrange
        $guardBookToDelete = GuardBook::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $this->object->id,
        ]);

        // Act
        $response = deleteJson("/v1/guard-books/{$guardBookToDelete->id}");

        // Assert
        $response->assertNoContent();
        $this->assertSoftDeleted('guard_books', ['id' => $guardBookToDelete->id]);
    });
});

describe('GuardBookController - Reports', function () {
    test('user can generate report', function () {
        // Arrange
        $data = [
            'period_start' => '2025-01-01T00:00:00Z',
            'period_end' => '2025-01-31T23:59:59Z',
        ];

        // Act
        $response = postJson("/v1/guard-books/{$this->guardBook->id}/reports", $data);

        // Assert
        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'report_number',
                    'period_start',
                    'period_end',
                    'total_events',
                    'generated_at',
                ],
            ]);

        $this->assertDatabaseHas('guard_book_reports', [
            'guard_book_id' => $this->guardBook->id,
        ]);
    });

    test('user can list reports of guard book', function () {
        // Arrange
        GuardBookReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'guard_book_id' => $this->guardBook->id,
            'generated_by_user_id' => $this->user->id,
        ]);

        // Act
        $response = getJson("/v1/guard-books/{$this->guardBook->id}/reports");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('GuardBookReportController', function () {
    beforeEach(function () {
        $this->report = GuardBookReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'guard_book_id' => $this->guardBook->id,
            'generated_by_user_id' => $this->user->id,
            'report_number' => 'GB-TEST-001',
        ]);
    });

    test('user can list guard book reports', function () {
        // Act
        $response = getJson('/v1/guard-book-reports');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    test('user can view guard book report', function () {
        // Act
        $response = getJson("/v1/guard-book-reports/{$this->report->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $this->report->id)
            ->assertJsonPath('data.report_number', 'GB-TEST-001');
    });

    test('user can export guard book report', function () {
        // Act
        $response = getJson("/v1/guard-book-reports/{$this->report->id}/export");

        // Assert
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Content-Disposition', "attachment; filename=\"{$this->report->report_number}.json\"");
    });

    test('user can delete guard book report', function () {
        // Act
        $response = deleteJson("/v1/guard-book-reports/{$this->report->id}");

        // Assert
        $response->assertNoContent();
        $this->assertSoftDeleted('guard_book_reports', ['id' => $this->report->id]);
    });
});
