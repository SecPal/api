<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('factory', 'unit');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('onboarding form template factory creates a tenant when none exists', function (): void {
    expect(TenantKey::count())->toBe(0);

    $template = OnboardingFormTemplate::factory()->create();

    expect($template->tenant_id)->not->toBeNull()
        ->and(TenantKey::count())->toBe(1)
        ->and($template->tenant)->toBeInstanceOf(TenantKey::class);
});

test('onboarding form template factory respects an explicit tenant id', function (): void {
    $tenant = TenantKey::factory()->create();

    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    expect($template->tenant_id)->toBe($tenant->id)
        ->and(TenantKey::count())->toBe(1);
});

test('system onboarding form template state stays tenantless', function (): void {
    $template = OnboardingFormTemplate::factory()->systemTemplate()->create();

    expect($template->tenant_id)->toBeNull()
        ->and($template->is_system_template)->toBeTrue()
        ->and(TenantKey::count())->toBe(0);
});
