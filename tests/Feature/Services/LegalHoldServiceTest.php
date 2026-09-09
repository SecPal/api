<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\LegalHoldStatus;
use App\Exceptions\DuplicateActiveLegalHoldAttachmentException;
use App\Exceptions\LegalHoldAttachmentAlreadyDetachedException;
use App\Exceptions\LegalHoldCaseReferenceConflictException;
use App\Exceptions\LegalHoldNotActiveException;
use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\TenantKey;
use App\Models\User;
use App\Repositories\LegalHoldRepository;
use App\Services\LegalHoldService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class)->group('serial');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function legalHoldActor(TenantKey $tenant, bool $authorized = true): User
{
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    if ($authorized) {
        givePermissionWithTenant($actor, $tenant->id, 'activity_log.read');
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    }

    return $actor;
}

test('legal hold service resolves through the Laravel container', function (): void {
    expect(app(LegalHoldService::class))->toBeInstanceOf(LegalHoldService::class);
});

test('authorized actor creates an active legal hold with server-derived identity', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);

    $hold = app(LegalHoldService::class)->create(
        $actor,
        'CASE-451',
        'Preserve evidence for the identified proceeding.',
    );

    expect($hold)->toBeInstanceOf(LegalHold::class)
        ->and($hold->tenant_id)->toBe($tenant->id)
        ->and($hold->status)->toBe(LegalHoldStatus::Active)
        ->and($hold->created_by_user_id)->toBe($actor->id)
        ->and($hold->created_by_identity_id)->toBe($actor->id)
        ->and($hold->released_at)->toBeNull();
});

test('case references conflict only inside the active tenant', function (): void {
    $tenantA = TenantKey::factory()->create();
    $tenantB = TenantKey::factory()->create();
    $actorA = legalHoldActor($tenantA);
    $service = app(LegalHoldService::class);
    $service->create($actorA, 'CASE-SHARED', 'First tenant case.');

    expect(fn () => $service->create($actorA, 'CASE-SHARED', 'Duplicate tenant case.'))
        ->toThrow(LegalHoldCaseReferenceConflictException::class);

    $actorB = legalHoldActor($tenantB);
    $holdB = $service->create($actorB, 'CASE-SHARED', 'Independent tenant case.');

    expect($holdB->tenant_id)->toBe($tenantB->id)
        ->and(LegalHold::query()->where('case_reference', 'CASE-SHARED')->count())->toBe(2);
});

test('inspection returns active and detached attachment history', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $service = app(LegalHoldService::class);
    $hold = $service->create($actor, 'CASE-HISTORY', 'Preserve the complete history.');
    $firstActivity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $secondActivity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $detached = $service->attach($actor, $hold->id, $firstActivity->id);
    $service->detach($actor, $hold->id, $detached->id, 'No longer in the current case scope.');
    $active = $service->attach($actor, $hold->id, $secondActivity->id);

    $inspected = $service->inspect($actor, $hold->id);

    expect($inspected->relationLoaded('attachments'))->toBeTrue()
        ->and($inspected->attachments)->toHaveCount(2)
        ->and($inspected->attachments->pluck('id')->all())->toContain($detached->id, $active->id)
        ->and($inspected->attachments->whereNull('detached_at'))->toHaveCount(1);
});

test('activity scope prevents inspection and detachment of inaccessible hold evidence', function (string $operation): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'organizational_unit_id' => App\Models\OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ])->id,
    ]);
    $attachment = LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $tenant->id,
        'legal_hold_id' => $hold->id,
        'activity_id' => $activity->id,
        'activity_identity_id' => $activity->id,
    ]);
    $service = app(LegalHoldService::class);

    $call = $operation === 'inspect'
        ? fn () => $service->inspect($actor, $hold->id)
        : fn () => $service->detach($actor, $hold->id, $attachment->id, 'Must remain unchanged.');

    expect($call)->toThrow(AuthorizationException::class)
        ->and($attachment->fresh()?->detached_at)->toBeNull()
        ->and($attachment->fresh()?->detachment_justification)->toBeNull();
})->with(['inspect', 'detach']);

test('inspection preserves attachment history after its live activity relation is gone', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $attachment = LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $tenant->id,
        'legal_hold_id' => $hold->id,
        'activity_id' => $activity->id,
        'activity_identity_id' => $activity->id,
    ]);
    app(LegalHoldService::class)->detach(
        $actor,
        $hold->id,
        $attachment->id,
        'The activity is no longer within the case scope.',
    );
    $activity->delete();

    $inspected = app(LegalHoldService::class)->inspect($actor, $hold->id);

    expect($inspected->attachments->pluck('id')->all())->toContain($attachment->id);
});

test('foreign tenant legal holds cannot be inspected or mutated', function (string $operation): void {
    $tenant = TenantKey::factory()->create();
    $foreignTenant = TenantKey::factory()->create();
    $foreignHold = LegalHold::factory()->create(['tenant_id' => $foreignTenant->id]);
    $actor = legalHoldActor($tenant);
    $service = app(LegalHoldService::class);

    $call = match ($operation) {
        'inspect' => fn () => $service->inspect($actor, $foreignHold->id),
        'attach' => fn () => $service->attach(
            $actor,
            $foreignHold->id,
            Activity::factory()->create(['tenant_id' => $tenant->id])->id,
        ),
        'release' => fn () => $service->release($actor, $foreignHold->id, 'Attempted foreign release.'),
    };

    expect($call)->toThrow(ModelNotFoundException::class)
        ->and($foreignHold->fresh()?->status)->toBe(LegalHoldStatus::Active)
        ->and(LegalHoldActivityAttachment::query()->count())->toBe(0);
})->with(['inspect', 'attach', 'release']);

test('attaching records authoritative activity and actor identity', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);

    $attachment = app(LegalHoldService::class)->attach($actor, $hold->id, $activity->id);

    expect($attachment->tenant_id)->toBe($tenant->id)
        ->and($attachment->activity_id)->toBe($activity->id)
        ->and($attachment->activity_identity_id)->toBe($activity->id)
        ->and($attachment->attached_by_user_id)->toBe($actor->id)
        ->and($attachment->attached_by_identity_id)->toBe($actor->id)
        ->and($attachment->attached_at)->not->toBeNull()
        ->and($attachment->detached_at)->toBeNull();
});

test('foreign and inaccessible activities cannot be attached', function (string $failure): void {
    $tenant = TenantKey::factory()->create();
    $foreignTenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);

    if ($failure === 'foreign tenant') {
        $activity = Activity::factory()->create(['tenant_id' => $foreignTenant->id]);
        $expected = ModelNotFoundException::class;
    } else {
        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => App\Models\OrganizationalUnit::factory()->create([
                'tenant_id' => $tenant->id,
            ])->id,
        ]);
        $expected = AuthorizationException::class;
    }

    expect(fn () => app(LegalHoldService::class)->attach($actor, $hold->id, $activity->id))
        ->toThrow($expected)
        ->and(LegalHoldActivityAttachment::query()->count())->toBe(0);
})->with(['foreign tenant', 'inaccessible scope']);

test('duplicate active attachment fails with a stable domain conflict', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LegalHoldService::class);
    $service->attach($actor, $hold->id, $activity->id);

    expect(fn () => $service->attach($actor, $hold->id, $activity->id))
        ->toThrow(DuplicateActiveLegalHoldAttachmentException::class)
        ->and(LegalHoldActivityAttachment::query()->count())->toBe(1);
});

test('detachment appends evidence without rewriting original attachment facts', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LegalHoldService::class);
    $attachment = $service->attach($actor, $hold->id, $activity->id);
    $originalAttachedAt = $attachment->attached_at;
    $originalAttacher = $attachment->attached_by_identity_id;

    $detached = $service->detach($actor, $hold->id, $attachment->id, 'Evidence left the case scope.');

    expect($detached->detached_at)->not->toBeNull()
        ->and($detached->detached_by_user_id)->toBe($actor->id)
        ->and($detached->detached_by_identity_id)->toBe($actor->id)
        ->and($detached->detachment_justification)->toBe('Evidence left the case scope.')
        ->and($detached->attached_at?->equalTo($originalAttachedAt))->toBeTrue()
        ->and($detached->attached_by_identity_id)->toBe($originalAttacher)
        ->and($detached->activity_identity_id)->toBe($activity->id);
});

test('second detachment fails without rewriting history', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LegalHoldService::class);
    $attachment = $service->attach(
        $actor,
        $hold->id,
        Activity::factory()->create(['tenant_id' => $tenant->id])->id,
    );
    $service->detach($actor, $hold->id, $attachment->id, 'Original reason.');
    $evidence = $attachment->fresh();

    expect(fn () => $service->detach($actor, $hold->id, $attachment->id, 'Replacement reason.'))
        ->toThrow(LegalHoldAttachmentAlreadyDetachedException::class)
        ->and($attachment->fresh()?->detached_at?->equalTo($evidence?->detached_at))->toBeTrue()
        ->and($attachment->fresh()?->detachment_justification)->toBe('Original reason.');
});

test('reattachment creates new active evidence and preserves detached history', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LegalHoldService::class);
    $original = $service->attach($actor, $hold->id, $activity->id);
    $service->detach($actor, $hold->id, $original->id, 'Temporarily outside scope.');
    $reattached = $service->attach($actor, $hold->id, $activity->id);

    expect($reattached->id)->not->toBe($original->id)
        ->and(LegalHoldActivityAttachment::query()->where('legal_hold_id', $hold->id)->count())->toBe(2)
        ->and(LegalHoldActivityAttachment::query()->where('legal_hold_id', $hold->id)->whereNull('detached_at')->count())->toBe(1)
        ->and($original->fresh()?->detachment_justification)->toBe('Temporarily outside scope.');
});

test('release records immutable evidence and preserves attachments', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LegalHoldService::class);
    $attachment = $service->attach(
        $actor,
        $hold->id,
        Activity::factory()->create(['tenant_id' => $tenant->id])->id,
    );

    $released = $service->release($actor, $hold->id, 'The proceeding has concluded.');

    expect($released->status)->toBe(LegalHoldStatus::Released)
        ->and($released->released_at)->not->toBeNull()
        ->and($released->released_by_user_id)->toBe($actor->id)
        ->and($released->released_by_identity_id)->toBe($actor->id)
        ->and($released->release_justification)->toBe('The proceeding has concluded.')
        ->and(LegalHoldActivityAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue();
});

test('released holds reject every further lifecycle mutation', function (string $operation): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LegalHoldService::class);
    $attachment = $service->attach($actor, $hold->id, $activity->id);
    $service->release($actor, $hold->id, 'The proceeding has concluded.');

    $call = match ($operation) {
        'attach' => fn () => $service->attach($actor, $hold->id, $activity->id),
        'detach' => fn () => $service->detach($actor, $hold->id, $attachment->id, 'Too late.'),
        'release' => fn () => $service->release($actor, $hold->id, 'Second release.'),
    };

    expect($call)->toThrow(LegalHoldNotActiveException::class)
        ->and($hold->fresh()?->release_justification)->toBe('The proceeding has concluded.')
        ->and($attachment->fresh()?->detached_at)->toBeNull();
})->with(['attach', 'detach', 'release']);

test('foreign tenant attachment cannot be detached through a local hold', function (): void {
    $tenant = TenantKey::factory()->create();
    $foreignTenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $foreignAttachment = LegalHoldActivityAttachment::factory()->create(['tenant_id' => $foreignTenant->id]);

    expect(fn () => app(LegalHoldService::class)->detach(
        $actor,
        $hold->id,
        $foreignAttachment->id,
        'Attempted foreign detachment.',
    ))->toThrow(ModelNotFoundException::class)
        ->and($foreignAttachment->fresh()?->detached_at)->toBeNull();
});

test('unauthorized or inactive tenant scope cannot inspect or mutate', function (string $failure): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant, authorized: false);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);

    if ($failure === 'tenant mismatch') {
        $otherTenant = TenantKey::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
    }

    expect(fn () => app(LegalHoldService::class)->inspect($actor, $hold->id))
        ->toThrow(AuthorizationException::class)
        ->and($hold->fresh()?->status)->toBe(LegalHoldStatus::Active);

    expect(fn () => app(LegalHoldService::class)->create($actor, 'CASE-UNAUTHORIZED', 'Must not persist.'))
        ->toThrow(AuthorizationException::class)
        ->and(LegalHold::query()->where('case_reference', 'CASE-UNAUTHORIZED')->exists())->toBeFalse();
})->with(['missing permission', 'tenant mismatch']);

test('authorized inspection remains available after release', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LegalHoldService::class);
    $service->release($actor, $hold->id, 'The proceeding has concluded.');

    expect($service->inspect($actor, $hold->id)->status)->toBe(LegalHoldStatus::Released);
});

test('server validates bounded non-empty lifecycle input before persistence', function (string $field, string $value): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $service = app(LegalHoldService::class);

    $call = $field === 'case reference'
        ? fn () => $service->create($actor, $value, 'Valid justification.')
        : fn () => $service->create($actor, 'CASE-BOUNDS', $value);

    expect($call)->toThrow(InvalidArgumentException::class)
        ->and(LegalHold::query()->count())->toBe(0);
})->with([
    'empty case reference' => ['case reference', '   '],
    'long case reference' => ['case reference', str_repeat('C', 65)],
    'empty justification' => ['justification', '   '],
    'long justification' => ['justification', str_repeat('J', 2001)],
]);

test('create rolls back when its repository fails after persistence', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $real = new LegalHoldRepository;
    $mock = Mockery::mock(LegalHoldRepository::class, function (MockInterface $mock) use ($real): void {
        $mock->shouldReceive('create')->once()->andReturnUsing(function (array $attributes) use ($real): never {
            $real->create($attributes);
            throw new RuntimeException('Injected create failure.');
        });
    });
    app()->instance(LegalHoldRepository::class, $mock);

    expect(fn () => app(LegalHoldService::class)->create($actor, 'CASE-ROLLBACK', 'Rollback evidence.'))
        ->toThrow(RuntimeException::class, 'Injected create failure.')
        ->and(LegalHold::query()->count())->toBe(0);
});

test('attach rolls back when its repository fails after persistence', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $real = new LegalHoldRepository;
    $mock = Mockery::mock(LegalHoldRepository::class)->makePartial();
    $mock->shouldReceive('attach')->once()->andReturnUsing(function (array $attributes) use ($real): never {
        $real->attach($attributes);
        throw new RuntimeException('Injected attach failure.');
    });
    app()->instance(LegalHoldRepository::class, $mock);

    expect(fn () => app(LegalHoldService::class)->attach($actor, $hold->id, $activity->id))
        ->toThrow(RuntimeException::class, 'Injected attach failure.')
        ->and(LegalHoldActivityAttachment::query()->count())->toBe(0);
});

test('detach rolls back when its repository fails after mutation', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LegalHoldService::class);
    $attachment = $service->attach(
        $actor,
        $hold->id,
        Activity::factory()->create(['tenant_id' => $tenant->id])->id,
    );
    $real = new LegalHoldRepository;
    $mock = Mockery::mock(LegalHoldRepository::class)->makePartial();
    $mock->shouldReceive('updateAttachment')->once()->andReturnUsing(
        function (LegalHoldActivityAttachment $record, array $attributes) use ($real): never {
            $real->updateAttachment($record, $attributes);
            throw new RuntimeException('Injected detach failure.');
        },
    );
    app()->instance(LegalHoldRepository::class, $mock);

    expect(fn () => app(LegalHoldService::class)->detach($actor, $hold->id, $attachment->id, 'Rollback evidence.'))
        ->toThrow(RuntimeException::class, 'Injected detach failure.')
        ->and($attachment->fresh()?->detached_at)->toBeNull()
        ->and($attachment->fresh()?->detached_by_identity_id)->toBeNull()
        ->and($attachment->fresh()?->detachment_justification)->toBeNull();
});

test('release rolls back when its repository fails after mutation', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $real = new LegalHoldRepository;
    $mock = Mockery::mock(LegalHoldRepository::class)->makePartial();
    $mock->shouldReceive('updateHold')->once()->andReturnUsing(
        function (LegalHold $record, array $attributes) use ($real): never {
            $real->updateHold($record, $attributes);
            throw new RuntimeException('Injected release failure.');
        },
    );
    app()->instance(LegalHoldRepository::class, $mock);

    expect(fn () => app(LegalHoldService::class)->release($actor, $hold->id, 'Rollback evidence.'))
        ->toThrow(RuntimeException::class, 'Injected release failure.')
        ->and($hold->fresh()?->status)->toBe(LegalHoldStatus::Active)
        ->and($hold->fresh()?->released_at)->toBeNull()
        ->and($hold->fresh()?->released_by_identity_id)->toBeNull()
        ->and($hold->fresh()?->release_justification)->toBeNull();
});
