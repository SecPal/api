<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use App\Support\ResidentialAddressHistorySchema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function loadResidedFromLabelMigration(): object
{
    return require database_path('migrations/2026_05_15_120000_update_residential_address_history_resided_from_label.php');
}

function globalResidentialTemplate(): OnboardingFormTemplate
{
    return OnboardingFormTemplate::query()
        ->whereNull('tenant_id')
        ->where('template_key', 'residential_address_history')
        ->firstOrFail();
}

test('up() updates global residential template schema to new resided_from label', function (): void {
    $template = globalResidentialTemplate();

    $migration = loadResidedFromLabelMigration();
    $migration->up();

    $template->refresh();

    $currentAddressResidedFrom = $template->form_schema['properties']['current_address']['properties']['resided_from'];
    expect($currentAddressResidedFrom['title'])->toBe('Date You Started Living There');
});

test('up() stores a schema equal to ResidentialAddressHistorySchema::definition()', function (): void {
    $migration = loadResidedFromLabelMigration();
    $migration->up();

    $template = globalResidentialTemplate();

    expect($template->form_schema)->toBe(ResidentialAddressHistorySchema::definition())
        ->and($template->form_schema['properties']['current_address']['properties']['resided_from']['title'])
        ->toBe('Date You Started Living There');
});

test('down() restores global template schema to legacy resided_from label', function (): void {
    $migration = loadResidedFromLabelMigration();
    $migration->up();
    $migration->down();

    $template = globalResidentialTemplate();

    $currentAddressResidedFrom = $template->form_schema['properties']['current_address']['properties']['resided_from'];
    expect($currentAddressResidedFrom['title'])->toBe('Living There Since');
});

test('down() leaves previous_addresses resided_from label unchanged', function (): void {
    $migration = loadResidedFromLabelMigration();
    $migration->up();
    $migration->down();

    $template = globalResidentialTemplate();

    $previousAddressResidedFrom = $template->form_schema['properties']['previous_addresses']['items']['properties']['resided_from'];
    expect($previousAddressResidedFrom['title'])->toBe('Resided From');
});

test('up() does not touch tenant-scoped residential templates', function (): void {
    $tenant = TenantKey::factory()->create();

    $tenantTemplate = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'residential_address_history',
        'form_schema' => ['type' => 'object', 'properties' => ['legacy_field' => ['type' => 'string']]],
    ]);

    $migration = loadResidedFromLabelMigration();
    $migration->up();

    $tenantTemplate->refresh();

    expect($tenantTemplate->form_schema)->toBe(['type' => 'object', 'properties' => ['legacy_field' => ['type' => 'string']]]);
});
