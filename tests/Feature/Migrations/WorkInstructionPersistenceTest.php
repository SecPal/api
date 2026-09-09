<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\ContentLocale;
use App\Enums\WorkInstructionStatus;
use App\Models\Employee;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\WorkInstruction;
use App\Models\WorkInstructionAcknowledgment;
use App\Models\WorkInstructionStandardBlock;
use App\Models\WorkInstructionStandardBlockTranslation;
use App\Models\WorkInstructionTemplate;
use App\Models\WorkInstructionTemplateTranslation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('serial');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workInstructionRow(TenantKey $tenant, array $overrides = []): array
{
    return array_replace([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $tenant->id,
        'instruction_number' => 'WI-'.Str::upper(Str::random(10)),
        'title' => 'Emergency procedure',
        'body' => 'Follow the documented emergency procedure.',
        'locale' => ContentLocale::English->value,
        'status' => WorkInstructionStatus::Draft->value,
        'published_at' => null,
        'published_by_user_id' => null,
        'archived_at' => null,
        'archived_by_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

test('creates the canonical PostgreSQL work instruction persistence tables', function (): void {
    expect((int) DB::scalar('SHOW server_version_num'))->toBeGreaterThanOrEqual(180000)
        ->and(Schema::hasColumns('work_instructions', [
            'id', 'tenant_id', 'instruction_number', 'title', 'body', 'locale', 'status',
            'published_at', 'published_by_user_id', 'archived_at', 'archived_by_user_id',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('work_instruction_templates', [
            'id', 'tenant_id', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('work_instruction_template_translations', [
            'id', 'tenant_id', 'work_instruction_template_id', 'locale', 'title', 'body',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('work_instruction_standard_blocks', [
            'id', 'key', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('work_instruction_standard_blocks', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumns('work_instruction_standard_block_translations', [
            'id', 'work_instruction_standard_block_id', 'locale', 'title', 'body',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('work_instruction_acknowledgments', [
            'id', 'tenant_id', 'work_instruction_id', 'employee_id',
            'acknowledged_by_user_id', 'acknowledged_at', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

test('declares the tenant lifecycle localization and acknowledgment boundaries in PostgreSQL', function (): void {
    $constraints = DB::table('pg_constraint')
        ->whereRaw('connamespace = current_schema()::regnamespace')
        ->whereIn('conname', [
            'work_instructions_status_check',
            'work_instructions_tenant_number_unique',
            'wi_template_translations_tenant_owner_foreign',
            'wi_template_translations_owner_locale_unique',
            'wi_block_translations_owner_locale_unique',
            'wi_acknowledgments_tenant_instruction_foreign',
            'wi_acknowledgments_tenant_employee_foreign',
            'wi_acknowledgments_identity_unique',
        ])
        ->orderBy('conname')
        ->pluck('conname')
        ->all();

    expect($constraints)->toBe([
        'wi_acknowledgments_identity_unique',
        'wi_acknowledgments_tenant_employee_foreign',
        'wi_acknowledgments_tenant_instruction_foreign',
        'wi_block_translations_owner_locale_unique',
        'wi_template_translations_owner_locale_unique',
        'wi_template_translations_tenant_owner_foreign',
        'work_instructions_status_check',
        'work_instructions_tenant_number_unique',
    ]);
});

test('scopes immutable instruction numbers to one tenant', function (): void {
    $tenant = TenantKey::factory()->create();
    DB::table('work_instructions')->insert(workInstructionRow($tenant, ['instruction_number' => 'WI-2026-0042']));
    DB::table('work_instructions')->insert(workInstructionRow($tenant, ['instruction_number' => 'WI-2026-0042']));
})->throws(QueryException::class);

test('allows the same instruction number in independent tenants', function (): void {
    $tenantA = TenantKey::factory()->create();
    $tenantB = TenantKey::factory()->create();

    DB::table('work_instructions')->insert(workInstructionRow($tenantA, ['instruction_number' => 'WI-2026-0042']));
    DB::table('work_instructions')->insert(workInstructionRow($tenantB, ['instruction_number' => 'WI-2026-0042']));

    expect(DB::table('work_instructions')->where('instruction_number', 'WI-2026-0042')->count())->toBe(2);
});

test('rejects lifecycle values outside the authoritative vocabulary', function (): void {
    $tenant = TenantKey::factory()->create();
    DB::table('work_instructions')->insert(workInstructionRow($tenant, ['status' => 'deleted']));
})->throws(QueryException::class);

test('rejects contradictory lifecycle timestamp combinations', function (array $state): void {
    $tenant = TenantKey::factory()->create();
    DB::table('work_instructions')->insert(workInstructionRow($tenant, $state));
})->with([
    'draft with publication timestamp' => [[
        'status' => 'draft',
        'published_at' => now(),
    ]],
    'published without publication timestamp' => [[
        'status' => 'published',
    ]],
    'published with archive timestamp' => [[
        'status' => 'published',
        'published_at' => now()->subMinute(),
        'archived_at' => now(),
    ]],
    'archived without publication timestamp' => [[
        'status' => 'archived',
        'archived_at' => now(),
    ]],
    'archive before publication' => [[
        'status' => 'archived',
        'published_at' => now(),
        'archived_at' => now()->subMinute(),
    ]],
])->throws(QueryException::class);

test('rejects cross-tenant publication and archival actors', function (string $actorColumn, string $status): void {
    $tenant = TenantKey::factory()->create();
    $otherTenant = TenantKey::factory()->create();
    $foreignActor = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $timestamps = $status === 'published'
        ? ['published_at' => now()]
        : ['published_at' => now()->subMinute(), 'archived_at' => now()];

    DB::table('work_instructions')->insert(workInstructionRow($tenant, $timestamps + [
        'status' => $status,
        $actorColumn => $foreignActor->id,
    ]));
})->with([
    'publisher' => ['published_by_user_id', 'published'],
    'archiver' => ['archived_by_user_id', 'archived'],
])->throws(QueryException::class);

test('rejects cross-tenant template translations', function (): void {
    $tenantA = TenantKey::factory()->create();
    $tenantB = TenantKey::factory()->create();
    $template = WorkInstructionTemplate::factory()->create(['tenant_id' => $tenantA->id]);

    WorkInstructionTemplateTranslation::factory()->create([
        'tenant_id' => $tenantB->id,
        'work_instruction_template_id' => $template->id,
    ]);
})->throws(QueryException::class);

test('rejects duplicate localized content identities', function (string $translationModel, string $ownerColumn, string $ownerModel): void {
    $owner = $ownerModel::factory()->create();
    $attributes = [
        $ownerColumn => $owner->id,
        'locale' => ContentLocale::German,
    ];

    if ($owner instanceof WorkInstructionTemplate) {
        $attributes['tenant_id'] = $owner->tenant_id;
    }

    $translationModel::factory()->create($attributes);
    $translationModel::factory()->create($attributes);
})->with([
    'tenant template' => [
        WorkInstructionTemplateTranslation::class,
        'work_instruction_template_id',
        WorkInstructionTemplate::class,
    ],
    'system standard block' => [
        WorkInstructionStandardBlockTranslation::class,
        'work_instruction_standard_block_id',
        WorkInstructionStandardBlock::class,
    ],
])->throws(QueryException::class);

test('prevents duplicate acknowledgment identities at the database boundary', function (): void {
    $acknowledgment = WorkInstructionAcknowledgment::factory()->create();

    WorkInstructionAcknowledgment::factory()->create([
        'tenant_id' => $acknowledgment->tenant_id,
        'work_instruction_id' => $acknowledgment->work_instruction_id,
        'employee_id' => $acknowledgment->employee_id,
    ]);
})->throws(QueryException::class);

test('allows independent acknowledgment identities', function (): void {
    $first = WorkInstructionAcknowledgment::factory()->create();
    $otherInstruction = WorkInstruction::factory()->published()->create(['tenant_id' => $first->tenant_id]);
    $otherEmployee = Employee::factory()->create(['tenant_id' => $first->tenant_id]);

    WorkInstructionAcknowledgment::factory()->create([
        'tenant_id' => $first->tenant_id,
        'work_instruction_id' => $otherInstruction->id,
        'employee_id' => $first->employee_id,
    ]);
    WorkInstructionAcknowledgment::factory()->create([
        'tenant_id' => $first->tenant_id,
        'work_instruction_id' => $first->work_instruction_id,
        'employee_id' => $otherEmployee->id,
    ]);

    expect(WorkInstructionAcknowledgment::query()->count())->toBe(3);
});

test('rejects every cross-tenant acknowledgment relation', function (string $foreignColumn, string $foreignModel): void {
    $acknowledgment = WorkInstructionAcknowledgment::factory()->make();
    $otherTenant = TenantKey::factory()->create();
    $foreign = match ($foreignModel) {
        WorkInstruction::class => WorkInstruction::factory()->published()->create(['tenant_id' => $otherTenant->id]),
        Employee::class => Employee::factory()->create(['tenant_id' => $otherTenant->id]),
        User::class => User::factory()->create(['tenant_id' => $otherTenant->id]),
    };

    $acknowledgment->setAttribute($foreignColumn, $foreign->id);
    $acknowledgment->save();
})->with([
    'instruction' => ['work_instruction_id', WorkInstruction::class],
    'employee' => ['employee_id', Employee::class],
    'actor' => ['acknowledged_by_user_id', User::class],
])->throws(QueryException::class);

test('retains acknowledgment evidence when its instruction is archived and prevents instruction deletion', function (): void {
    $acknowledgment = WorkInstructionAcknowledgment::factory()->create();
    $instruction = $acknowledgment->workInstruction;

    $instruction->update([
        'status' => WorkInstructionStatus::Archived,
        'archived_at' => now(),
    ]);

    expect($acknowledgment->fresh())->not->toBeNull();

    DB::table('work_instructions')->where('id', $instruction->id)->delete();
})->throws(QueryException::class);

test('retains instruction and acknowledgment evidence when actor accounts are deleted', function (): void {
    $acknowledgment = WorkInstructionAcknowledgment::factory()->create();
    $instruction = $acknowledgment->workInstruction;
    $publisher = $instruction->publishedBy;
    $acknowledgingActor = $acknowledgment->acknowledgedBy;

    expect($publisher)->not->toBeNull()
        ->and($acknowledgingActor)->not->toBeNull();

    if (! $publisher instanceof User || ! $acknowledgingActor instanceof User) {
        throw new RuntimeException('Factories must create attributable user actors.');
    }

    $publisher->delete();
    $acknowledgingActor->delete();

    expect($instruction->fresh()?->status)->toBe(WorkInstructionStatus::Published)
        ->and($instruction->fresh()?->published_by_user_id)->toBeNull()
        ->and($acknowledgment->fresh()?->acknowledged_by_user_id)->toBeNull()
        ->and($acknowledgment->fresh()?->employee_id)->toBe($acknowledgment->employee_id);
});

test('models and factories expose tenant-safe aggregate relationships', function (): void {
    $draft = WorkInstruction::factory()->draft()->create();
    $instruction = WorkInstruction::factory()->inReview()->create();
    $published = WorkInstruction::factory()->published()->create(['tenant_id' => $instruction->tenant_id]);
    $archived = WorkInstruction::factory()->archived()->create(['tenant_id' => $instruction->tenant_id]);
    $template = WorkInstructionTemplate::factory()->create(['tenant_id' => $instruction->tenant_id]);
    $templateTranslation = WorkInstructionTemplateTranslation::factory()->create([
        'tenant_id' => $template->tenant_id,
        'work_instruction_template_id' => $template->id,
    ]);
    $standardBlock = WorkInstructionStandardBlock::factory()->create();
    $blockTranslation = WorkInstructionStandardBlockTranslation::factory()->create([
        'work_instruction_standard_block_id' => $standardBlock->id,
    ]);
    $acknowledgment = WorkInstructionAcknowledgment::factory()->create([
        'tenant_id' => $published->tenant_id,
        'work_instruction_id' => $published->id,
    ]);

    expect($draft->status)->toBe(WorkInstructionStatus::Draft)
        ->and($instruction->status)->toBe(WorkInstructionStatus::InReview)
        ->and($instruction->locale)->toBeInstanceOf(ContentLocale::class)
        ->and($instruction->tenant->is($template->tenant))->toBeTrue()
        ->and($published->published_at)->not->toBeNull()
        ->and($archived->published_at)->not->toBeNull()
        ->and($archived->archived_at)->not->toBeNull()
        ->and($templateTranslation->template->is($template))->toBeTrue()
        ->and($template->translations->first()?->is($templateTranslation))->toBeTrue()
        ->and($blockTranslation->standardBlock->is($standardBlock))->toBeTrue()
        ->and($standardBlock->translations->first()?->is($blockTranslation))->toBeTrue()
        ->and($acknowledgment->tenant->is($published->tenant))->toBeTrue()
        ->and($acknowledgment->workInstruction->is($published))->toBeTrue()
        ->and($acknowledgment->employee->tenant_id)->toBe($published->tenant_id)
        ->and($acknowledgment->acknowledgedBy->tenant_id)->toBe($published->tenant_id)
        ->and($published->tenant->workInstructions->contains($published))->toBeTrue()
        ->and($acknowledgment->employee->workInstructionAcknowledgments->contains($acknowledgment))->toBeTrue()
        ->and($acknowledgment->acknowledgedBy->workInstructionAcknowledgments->contains($acknowledgment))->toBeTrue();
});

test('the migration rolls back and reapplies without orphaned schema objects', function (): void {
    $migration = require database_path('migrations/2026_09_09_120000_create_work_instruction_persistence.php');
    $migration->down();

    $constraintNames = DB::table('pg_constraint')
        ->whereRaw('connamespace = current_schema()::regnamespace')
        ->where('conname', 'like', 'wi_%')
        ->pluck('conname')
        ->all();

    expect(Schema::hasTable('work_instructions'))->toBeFalse()
        ->and(Schema::hasTable('work_instruction_templates'))->toBeFalse()
        ->and(Schema::hasTable('work_instruction_standard_blocks'))->toBeFalse()
        ->and(Schema::hasTable('work_instruction_acknowledgments'))->toBeFalse()
        ->and($constraintNames)->toBe([]);

    $migration->up();

    expect(Schema::hasTable('work_instructions'))->toBeTrue()
        ->and(Schema::hasTable('work_instruction_templates'))->toBeTrue()
        ->and(Schema::hasTable('work_instruction_standard_blocks'))->toBeTrue()
        ->and(Schema::hasTable('work_instruction_acknowledgments'))->toBeTrue();
});
