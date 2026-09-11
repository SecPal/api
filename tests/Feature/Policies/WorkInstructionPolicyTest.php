<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\TenantKey;
use App\Models\User;
use App\Models\WorkInstruction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('Work Instruction policy maps exactly the accepted capabilities', function (string $ability, string $permission): void {
    givePermissionWithTenant($this->user, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $instruction = WorkInstruction::factory()->create(['tenant_id' => $this->tenant->id]);
    $target = in_array($ability, ['viewAny', 'create'], true) ? WorkInstruction::class : $instruction;

    expect(Gate::forUser($this->user)->allows($ability, $target))->toBeTrue();
})->with([
    ['viewAny', 'work_instructions.read'],
    ['view', 'work_instructions.read'],
    ['create', 'work_instructions.create'],
    ['update', 'work_instructions.update'],
    ['publish', 'work_instructions.publish'],
    ['archive', 'work_instructions.archive'],
]);

test('policy rejects foreign instances stale aliases and mismatched tenant context', function (): void {
    givePermissionWithTenant($this->user, $this->tenant->id, 'work_instructions.update');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreign = WorkInstruction::factory()->create(['tenant_id' => $foreignTenant->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    expect(Gate::forUser($this->user)->allows('update', $foreign))->toBeFalse();

    app(PermissionRegistrar::class)->setPermissionsTeamId($foreignTenant->id);
    expect(Gate::forUser($this->user)->allows('update', WorkInstruction::class))->toBeFalse();
});
