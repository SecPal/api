<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\WorkInstruction;
use App\Services\WorkInstructionAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->actor->createToken('work-instruction-api')->plainTextToken;
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function grantWorkInstructionApiPermissions(object $test, string ...$permissions): void
{
    foreach ($permissions as $permission) {
        givePermissionWithTenant($test->actor, $test->tenant->id, $permission);
    }
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function validWorkInstructionPayload(array $overrides = []): array
{
    return array_merge([
        'instruction_number' => ' WI-2026-0042 ',
        'title' => ' Emergency evacuation procedure ',
        'body' => "Line one.\n\nLine two.",
        'locale' => 'de',
    ], $overrides);
}

function postWorkInstructionAction(object $test, string $path): Illuminate\Testing\TestResponse
{
    return $test->withToken($test->token)
        ->withHeader('Accept', 'application/json')
        ->post($path);
}

test('all seven routes require authentication and their exact capability', function (): void {
    $this->getJson('/v1/work-instructions')->assertUnauthorized();

    foreach ([
        ['GET', '/v1/work-instructions'],
        ['POST', '/v1/work-instructions'],
        ['GET', '/v1/work-instructions/11111111-1111-4111-8111-111111111111'],
        ['PATCH', '/v1/work-instructions/11111111-1111-4111-8111-111111111111'],
        ['POST', '/v1/work-instructions/11111111-1111-4111-8111-111111111111/submit-for-review'],
        ['POST', '/v1/work-instructions/11111111-1111-4111-8111-111111111111/publish'],
        ['POST', '/v1/work-instructions/11111111-1111-4111-8111-111111111111/archive'],
    ] as [$method, $path]) {
        $this->withToken($this->token)->json($method, $path)->assertForbidden();
    }
});

test('list is tenant isolated includes every state and paginates deterministically', function (): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.read');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    WorkInstruction::factory()->create(['tenant_id' => $foreignTenant->id]);
    $createdAt = now()->subHour()->startOfSecond();

    WorkInstruction::factory()->count(13)->sequence(
        fn ($sequence): array => [
            'tenant_id' => $this->tenant->id,
            'created_at' => $createdAt->copy()->subMinutes($sequence->index + 1),
        ],
    )->create();
    WorkInstruction::factory()->draft()->create(['tenant_id' => $this->tenant->id, 'created_at' => $createdAt]);
    WorkInstruction::factory()->inReview()->create(['tenant_id' => $this->tenant->id, 'created_at' => $createdAt]);
    WorkInstruction::factory()->published()->create(['tenant_id' => $this->tenant->id, 'created_at' => $createdAt]);
    WorkInstruction::factory()->archived()->create(['tenant_id' => $this->tenant->id, 'created_at' => $createdAt]);

    $expected = WorkInstruction::query()->forTenant($this->tenant->id)
        ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->pluck('id')->all();
    $response = $this->withToken($this->token)->getJson('/v1/work-instructions');

    $response->assertOk()->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 17);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($expected)
        ->and(collect($response->json('data'))->pluck('status')->unique()->sort()->values()->all())
        ->toBe(['archived', 'draft', 'in_review', 'published']);
});

test('list rejects invalid pagination and every unsupported query', function (string $query): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.read');

    $this->withToken($this->token)->getJson('/v1/work-instructions?'.$query)
        ->assertUnprocessable();
})->with([
    'page=0', 'page=nope', 'per_page=0', 'per_page=101', 'per_page=nope',
    'status=draft', 'locale=de', 'search=evacuation', 'sort=id', 'tenant_id=999',
    '0=foo',
]);

test('create and PATCH reject dotted literal unknown JSON properties', function (string $method): void {
    grantWorkInstructionApiPermissions(
        $this,
        'work_instructions.create',
        'work_instructions.update',
    );

    $response = $method === 'create'
        ? $this->withToken($this->token)->postJson('/v1/work-instructions', validWorkInstructionPayload([
            'metadata.extra' => 'injected',
        ]))
        : $this->withToken($this->token)->patchJson(
            '/v1/work-instructions/'.WorkInstruction::factory()->create(['tenant_id' => $this->tenant->id])->id,
            ['metadata.extra' => 'injected'],
        );

    $response->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toContain('metadata.extra');
})->with(['create', 'patch']);

test('create derives tenant and draft lifecycle preserves content and audits safely', function (): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.create');
    $this->travelTo(now()->startOfSecond());

    $response = $this->withToken($this->token)
        ->postJson('/v1/work-instructions', validWorkInstructionPayload());

    $response->assertCreated()
        ->assertJsonPath('data.instruction_number', ' WI-2026-0042 ')
        ->assertJsonPath('data.title', ' Emergency evacuation procedure ')
        ->assertJsonPath('data.body', "Line one.\n\nLine two.")
        ->assertJsonPath('data.locale', 'de')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.published_at', null)
        ->assertJsonPath('data.published_by_user_id', null)
        ->assertJsonPath('data.archived_at', null)
        ->assertJsonPath('data.archived_by_user_id', null)
        ->assertJsonMissingPath('data.tenant_id');
    expect(array_keys($response->json('data')))->toBe([
        'id', 'instruction_number', 'title', 'body', 'locale', 'status',
        'published_at', 'published_by_user_id', 'archived_at', 'archived_by_user_id',
        'created_at', 'updated_at',
    ])->and($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $instruction = WorkInstruction::query()->sole();
    expect($instruction->tenant_id)->toBe($this->tenant->id)
        ->and($instruction->instruction_number)->toBe(' WI-2026-0042 ');
    $audit = Activity::query()->where('event', 'work_instruction.create')->sole();
    expect($audit->tenant_id)->toBe($this->tenant->id)
        ->and($audit->causer_id)->toBe($this->actor->id)
        ->and($audit->subject_id)->toBe($instruction->id)
        ->and($audit->properties->has('request'))->toBeFalse()
        ->and(json_encode($audit->properties->all(), JSON_THROW_ON_ERROR))->not->toContain('Line one');
});

test('instruction number uniqueness is tenant scoped and conflicts are closed', function (): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.create');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    WorkInstruction::factory()->create([
        'tenant_id' => $foreignTenant->id,
        'instruction_number' => 'WI-SHARED',
    ]);

    $this->withToken($this->token)->postJson('/v1/work-instructions', validWorkInstructionPayload([
        'instruction_number' => 'WI-SHARED',
    ]))->assertCreated();

    $this->withToken($this->token)->postJson('/v1/work-instructions', validWorkInstructionPayload([
        'instruction_number' => 'WI-SHARED',
    ]))->assertConflict()->assertExactJson([
        'message' => 'The instruction number is already in use.',
        'code' => 'CONFLICT',
    ]);
});

test('create enforces the exact closed nonblank request contract', function (string $case, string $field): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.create');
    $payload = match ($case) {
        'missing number' => validWorkInstructionPayload(['instruction_number' => null]),
        'blank number' => validWorkInstructionPayload(['instruction_number' => '   ']),
        'long number' => validWorkInstructionPayload(['instruction_number' => str_repeat('n', 65)]),
        'missing title' => validWorkInstructionPayload(['title' => null]),
        'blank title' => validWorkInstructionPayload(['title' => " \t\n"]),
        'long title' => validWorkInstructionPayload(['title' => str_repeat('t', 256)]),
        'missing body' => validWorkInstructionPayload(['body' => null]),
        'blank body' => validWorkInstructionPayload(['body' => " \t\n"]),
        'locale' => validWorkInstructionPayload(['locale' => 'fr']),
        default => validWorkInstructionPayload([$case => 'injected']),
    };
    if (str_starts_with($case, 'missing ')) {
        unset($payload[$field]);
    }

    $this->withToken($this->token)->postJson('/v1/work-instructions', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['missing number', 'instruction_number'], ['blank number', 'instruction_number'],
    ['long number', 'instruction_number'], ['missing title', 'title'], ['blank title', 'title'],
    ['long title', 'title'], ['missing body', 'body'], ['blank body', 'body'], ['locale', 'locale'],
    ['id', 'id'], ['tenant_id', 'tenant_id'], ['status', 'status'], ['published_at', 'published_at'],
    ['published_by_user_id', 'published_by_user_id'], ['archived_at', 'archived_at'],
    ['archived_by_user_id', 'archived_by_user_id'], ['created_at', 'created_at'],
    ['updated_at', 'updated_at'], ['version', 'version'], ['scope', 'scope'],
    ['template_id', 'template_id'], ['requires_acknowledgment', 'requires_acknowledgment'],
    ['unknown', 'unknown'],
]);

test('inspect is tenant safe distinguishes malformed UUID and serializes no nested data', function (string $kind): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.read');
    $id = 'not-a-uuid';
    if ($kind === 'local') {
        $id = WorkInstruction::factory()->create(['tenant_id' => $this->tenant->id])->id;
    } elseif ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = WorkInstruction::factory()->create(['tenant_id' => $foreignTenant->id])->id;
    } elseif ($kind === 'missing') {
        $id = (string) Str::uuid();
    }

    $response = $this->withToken($this->token)->getJson('/v1/work-instructions/'.$id);
    if ($kind === 'local') {
        $response->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.publisher')
            ->assertJsonMissingPath('data.archiver')
            ->assertJsonMissingPath('data.acknowledgments');
    } elseif ($kind === 'malformed') {
        $response->assertUnprocessable()->assertJsonValidationErrors('work_instruction');
    } else {
        $response->assertNotFound()->assertExactJson([
            'message' => 'Resource not found',
            'code' => 'NOT_FOUND',
        ]);
    }
})->with(['local', 'foreign', 'missing', 'malformed']);

test('every mutation reports malformed UUIDs with the contract validation key', function (string $method, string $suffix): void {
    grantWorkInstructionApiPermissions(
        $this,
        'work_instructions.update',
        'work_instructions.publish',
        'work_instructions.archive',
    );

    $this->withToken($this->token)
        ->json($method, '/v1/work-instructions/not-a-uuid'.$suffix, $method === 'PATCH' ? ['title' => 'Updated'] : [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('work_instruction');
})->with([
    'update' => ['PATCH', ''],
    'submit' => ['POST', '/submit-for-review'],
    'publish' => ['POST', '/publish'],
    'archive' => ['POST', '/archive'],
]);

test('cross-tenant mutations return the same neutral not-found response', function (string $operation): void {
    grantWorkInstructionApiPermissions(
        $this,
        'work_instructions.update',
        'work_instructions.publish',
        'work_instructions.archive',
    );
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $factory = WorkInstruction::factory()->state(['tenant_id' => $foreignTenant->id]);
    $instruction = match ($operation) {
        'patch', 'submit-for-review' => $factory->draft()->create(),
        'publish' => $factory->inReview()->create(),
        'archive' => $factory->published()->create(),
    };
    $path = '/v1/work-instructions/'.$instruction->id;
    $response = $operation === 'patch'
        ? $this->withToken($this->token)->patchJson($path, ['title' => 'Forbidden'])
        : postWorkInstructionAction($this, $path.'/'.$operation);

    $response->assertNotFound()->assertExactJson([
        'message' => 'Resource not found',
        'code' => 'NOT_FOUND',
    ]);
})->with(['patch', 'submit-for-review', 'publish', 'archive']);

test('PATCH updates only editable content in draft and in-review states', function (string $state): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.update');
    $tenantId = $this->tenant->id;
    $factory = WorkInstruction::factory()->state(fn (): array => ['tenant_id' => $tenantId]);
    $instruction = $state === 'draft' ? $factory->draft()->create() : $factory->inReview()->create();
    $number = $instruction->instruction_number;

    $this->withToken($this->token)->patchJson('/v1/work-instructions/'.$instruction->id, [
        'title' => ' Revised title ',
        'body' => "Revised\nbody",
        'locale' => 'en',
    ])->assertOk()
        ->assertJsonPath('data.title', ' Revised title ')
        ->assertJsonPath('data.body', "Revised\nbody")
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.status', $state);

    expect($instruction->fresh()?->instruction_number)->toBe($number)
        ->and(Activity::query()->where('event', 'work_instruction.update')->count())->toBe(1);
})->with(['draft', 'in_review']);

test('PATCH rejects published or archived state without writes', function (string $state): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.update');
    $tenantId = $this->tenant->id;
    $factory = WorkInstruction::factory()->state(fn (): array => [
        'tenant_id' => $tenantId,
        'title' => 'Frozen title',
    ]);
    $instruction = $state === 'published' ? $factory->published()->create() : $factory->archived()->create();

    $this->withToken($this->token)->patchJson('/v1/work-instructions/'.$instruction->id, [
        'title' => 'Forbidden rewrite',
    ])->assertConflict()->assertExactJson([
        'message' => 'The Work Instruction is not editable.',
        'code' => 'CONFLICT',
    ]);

    expect($instruction->fresh()?->title)->toBe('Frozen title')
        ->and(Activity::query()->where('event', 'work_instruction.update')->exists())->toBeFalse();
})->with(['published', 'archived']);

test('PATCH is non-empty closed and keeps immutable and lifecycle fields server-owned', function (array $payload, string $field): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.update');
    $instruction = WorkInstruction::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->withToken($this->token)->patchJson('/v1/work-instructions/'.$instruction->id, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'empty' => [[], 'work_instruction'],
    'instruction number' => [['instruction_number' => 'WI-NEW'], 'instruction_number'],
    'id' => [['id' => (string) Str::uuid()], 'id'],
    'tenant' => [['tenant_id' => 9], 'tenant_id'],
    'status' => [['status' => 'published'], 'status'],
    'published at' => [['published_at' => now()->toIso8601String()], 'published_at'],
    'publisher' => [['published_by_user_id' => (string) Str::uuid()], 'published_by_user_id'],
    'archived at' => [['archived_at' => now()->toIso8601String()], 'archived_at'],
    'archiver' => [['archived_by_user_id' => (string) Str::uuid()], 'archived_by_user_id'],
    'created at' => [['created_at' => now()->toIso8601String()], 'created_at'],
    'updated at' => [['updated_at' => now()->toIso8601String()], 'updated_at'],
    'unknown' => [['sections' => []], 'sections'],
    'blank title' => [['title' => '   '], 'title'],
    'long title' => [['title' => str_repeat('t', 256)], 'title'],
    'blank body' => [['body' => "\n\t"], 'body'],
    'locale' => [['locale' => 'fr'], 'locale'],
]);

test('forward lifecycle records server evidence preserves frozen content and audits', function (): void {
    grantWorkInstructionApiPermissions(
        $this,
        'work_instructions.update',
        'work_instructions.publish',
        'work_instructions.archive',
    );
    $instruction = WorkInstruction::factory()->create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Final title',
        'body' => "Final\nbody",
    ]);

    postWorkInstructionAction($this, "/v1/work-instructions/{$instruction->id}/submit-for-review")
        ->assertOk()->assertJsonPath('data.status', 'in_review');

    $this->travel(1)->second();
    $published = postWorkInstructionAction($this, "/v1/work-instructions/{$instruction->id}/publish")
        ->assertOk()->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.published_by_user_id', $this->actor->id)
        ->assertJsonPath('data.archived_at', null)
        ->assertJsonPath('data.archived_by_user_id', null);
    expect($published->json('data.title'))->toBe('Final title')
        ->and($published->json('data.body'))->toBe("Final\nbody")
        ->and($published->json('data.published_at'))->toBeString();
    $publishedAt = $published->json('data.published_at');

    $this->travel(1)->second();
    postWorkInstructionAction($this, "/v1/work-instructions/{$instruction->id}/archive")
        ->assertOk()->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.published_at', $publishedAt)
        ->assertJsonPath('data.published_by_user_id', $this->actor->id)
        ->assertJsonPath('data.archived_by_user_id', $this->actor->id);

    $current = $instruction->fresh();
    expect($current?->title)->toBe('Final title')
        ->and($current?->body)->toBe("Final\nbody")
        ->and($current?->archived_at?->greaterThanOrEqualTo($current->published_at))->toBeTrue()
        ->and(Activity::query()->whereIn('event', [
            'work_instruction.submit_for_review',
            'work_instruction.publish',
            'work_instruction.archive',
        ])->count())->toBe(3);
});

test('lifecycle transitions reject every invalid source state without partial writes', function (string $action, string $state, string $message): void {
    $permission = match ($action) {
        'submit-for-review' => 'work_instructions.update',
        'publish' => 'work_instructions.publish',
        'archive' => 'work_instructions.archive',
    };
    grantWorkInstructionApiPermissions($this, $permission);
    $tenantId = $this->tenant->id;
    $factory = WorkInstruction::factory()->state(fn (): array => ['tenant_id' => $tenantId]);
    $instruction = match ($state) {
        'draft' => $factory->draft()->create(),
        'in_review' => $factory->inReview()->create(),
        'published' => $factory->published()->create(),
        'archived' => $factory->archived()->create(),
    };

    postWorkInstructionAction($this, "/v1/work-instructions/{$instruction->id}/{$action}")
        ->assertConflict()->assertExactJson(['message' => $message, 'code' => 'CONFLICT']);
    expect($instruction->fresh()?->status->value)->toBe($state);
})->with([
    ['submit-for-review', 'in_review', 'The Work Instruction is not a draft.'],
    ['submit-for-review', 'published', 'The Work Instruction is not a draft.'],
    ['submit-for-review', 'archived', 'The Work Instruction is not a draft.'],
    ['publish', 'draft', 'The Work Instruction is not in review.'],
    ['publish', 'published', 'The Work Instruction is not in review.'],
    ['publish', 'archived', 'The Work Instruction is not in review.'],
    ['archive', 'draft', 'The Work Instruction is not published.'],
    ['archive', 'in_review', 'The Work Instruction is not published.'],
    ['archive', 'archived', 'The Work Instruction is not published.'],
]);

test('lifecycle actions reject bodies and mutation query parameters', function (string $action, string $kind): void {
    grantWorkInstructionApiPermissions(
        $this,
        'work_instructions.update',
        'work_instructions.publish',
        'work_instructions.archive',
    );
    $instruction = WorkInstruction::factory()->create(['tenant_id' => $this->tenant->id]);
    $path = "/v1/work-instructions/{$instruction->id}/{$action}";

    $response = $kind === 'body'
        ? $this->withToken($this->token)->postJson($path, ['status' => 'published'])
        : postWorkInstructionAction($this, $path.'?force=1');

    $response->assertUnprocessable();
})->with([
    ['submit-for-review', 'body'], ['submit-for-review', 'query'],
    ['publish', 'body'], ['publish', 'query'],
    ['archive', 'body'], ['archive', 'query'],
]);

test('nullable actor references do not erase durable lifecycle timestamps', function (): void {
    grantWorkInstructionApiPermissions($this, 'work_instructions.read');
    $publisher = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $archiver = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instruction = WorkInstruction::factory()->archived()->create([
        'tenant_id' => $this->tenant->id,
        'published_by_user_id' => $publisher->id,
        'archived_by_user_id' => $archiver->id,
    ]);
    $publisher->delete();
    $archiver->delete();

    $this->withToken($this->token)->getJson('/v1/work-instructions/'.$instruction->id)
        ->assertOk()
        ->assertJsonPath('data.published_by_user_id', null)
        ->assertJsonPath('data.archived_by_user_id', null)
        ->assertJsonPath('data.status', 'archived');
    expect($instruction->fresh()?->published_at)->not->toBeNull()
        ->and($instruction->fresh()?->archived_at)->not->toBeNull();
});

test('required publication and archive audit failure rolls back the mutation', function (string $operation): void {
    $permission = $operation === 'publish' ? 'work_instructions.publish' : 'work_instructions.archive';
    grantWorkInstructionApiPermissions($this, $permission);
    $factory = WorkInstruction::factory()->state(['tenant_id' => $this->tenant->id]);
    $instruction = $operation === 'publish'
        ? $factory->inReview()->create()
        : $factory->published()->create();
    $initialStatus = $instruction->status->value;
    $auditMethod = $operation === 'publish' ? 'recordPublish' : 'recordArchive';
    $this->mock(WorkInstructionAuditRecorder::class, function (MockInterface $mock) use ($auditMethod): void {
        $mock->shouldReceive('snapshot')->once()->andReturn([]);
        $mock->shouldReceive($auditMethod)->once()->andThrow(new RuntimeException('audit unavailable'));
    });

    postWorkInstructionAction($this, "/v1/work-instructions/{$instruction->id}/{$operation}")
        ->assertInternalServerError();
    expect($instruction->fresh()?->status->value)->toBe($initialStatus);
    if ($operation === 'publish') {
        expect($instruction->fresh()?->published_at)->toBeNull();
    } else {
        expect($instruction->fresh()?->archived_at)->toBeNull();
    }
})->with(['publish', 'archive']);

test('Work Instruction audits use the accepted security-record retention category', function (): void {
    expect(Activity::getRetentionYearsForLogType('work_instruction_change'))->toBe(3)
        ->and(Activity::getAllRetentionYears())->toHaveKey('work_instruction_change');
});
