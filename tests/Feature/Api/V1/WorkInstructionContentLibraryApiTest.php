<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\TenantKey;
use App\Models\User;
use App\Models\WorkInstructionStandardBlock;
use App\Models\WorkInstructionStandardBlockTranslation;
use App\Models\WorkInstructionTemplate;
use App\Models\WorkInstructionTemplateTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->actor->createToken('work-instruction-content')->plainTextToken;
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function grantContentPermission(object $test, string ...$permissions): void
{
    foreach ($permissions as $permission) {
        givePermissionWithTenant($test->actor, $test->tenant->id, $permission);
    }
}

/** @return array{translations: array<string, array{title: string, body: string}>} */
function contentTranslations(array $translations = []): array
{
    return ['translations' => $translations ?: [
        'de' => ['title' => 'Brandschutz', 'body' => 'Deutscher Inhalt'],
        'en' => ['title' => 'Fire safety', 'body' => 'English content'],
    ]];
}

/** @param array<string, array{title: string, body: string}> $translations */
function contentTemplate(int $tenantId, array $translations): WorkInstructionTemplate
{
    $template = WorkInstructionTemplate::factory()->create(['tenant_id' => $tenantId]);
    foreach ($translations as $locale => $translation) {
        WorkInstructionTemplateTranslation::factory()->create([
            'tenant_id' => $tenantId,
            'work_instruction_template_id' => $template->id,
            'locale' => $locale,
            ...$translation,
        ]);
    }

    return $template;
}

/** @param array<string, array{title: string, body: string}> $translations */
function standardBlock(array $translations): WorkInstructionStandardBlock
{
    $block = WorkInstructionStandardBlock::factory()->create();
    foreach ($translations as $locale => $translation) {
        WorkInstructionStandardBlockTranslation::factory()->create([
            'work_instruction_standard_block_id' => $block->id,
            'locale' => $locale,
            ...$translation,
        ]);
    }

    return $block;
}

test('the content library exposes exactly the accepted six operations and capability mappings', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/work-instruction-templates')
            || str_starts_with($route->uri(), 'v1/standard-blocks'))
        ->flatMap(fn ($route): array => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): array => [
                'signature' => $method.' '.$route->uri(),
                'operation_id' => $route->getName(),
                'permission' => collect($route->gatherMiddleware())
                    ->first(fn (string $middleware): bool => str_starts_with($middleware, 'permission:work_instructions.')),
            ])->all())
        ->keyBy('signature')->sortKeys();

    expect($routes->keys()->all())->toBe([
        'GET v1/standard-blocks',
        'GET v1/standard-blocks/{standardBlock}',
        'GET v1/work-instruction-templates',
        'GET v1/work-instruction-templates/{workInstructionTemplate}',
        'POST v1/work-instruction-templates',
        'PUT v1/work-instruction-templates/{workInstructionTemplate}',
    ])->and($routes->map(fn (array $route): array => Illuminate\Support\Arr::only($route, ['operation_id', 'permission']))->all())->toBe([
        'GET v1/standard-blocks' => ['operation_id' => 'listWorkInstructionStandardBlocks', 'permission' => 'permission:work_instructions.read'],
        'GET v1/standard-blocks/{standardBlock}' => ['operation_id' => 'getWorkInstructionStandardBlock', 'permission' => 'permission:work_instructions.read'],
        'GET v1/work-instruction-templates' => ['operation_id' => 'listWorkInstructionTemplates', 'permission' => 'permission:work_instructions.read'],
        'GET v1/work-instruction-templates/{workInstructionTemplate}' => ['operation_id' => 'getWorkInstructionTemplate', 'permission' => 'permission:work_instructions.read'],
        'POST v1/work-instruction-templates' => ['operation_id' => 'createWorkInstructionTemplate', 'permission' => 'permission:work_instructions.create'],
        'PUT v1/work-instruction-templates/{workInstructionTemplate}' => ['operation_id' => 'replaceWorkInstructionTemplateTranslations', 'permission' => 'permission:work_instructions.update'],
    ]);
});

test('all six operations require authentication and their exact capability', function (): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $operations = [
        ['GET', '/v1/work-instruction-templates'], ['POST', '/v1/work-instruction-templates'],
        ['GET', "/v1/work-instruction-templates/{$id}"], ['PUT', "/v1/work-instruction-templates/{$id}"],
        ['GET', '/v1/standard-blocks'], ['GET', "/v1/standard-blocks/{$id}"],
    ];
    foreach ($operations as [$method, $path]) {
        $this->json($method, $path)->assertUnauthorized();
    }
    foreach ($operations as [$method, $path]) {
        $this->withToken($this->token)->json($method, $path)->assertForbidden();
    }
});

test('template list is tenant isolated paginated ordered and localized without N plus one queries', function (): void {
    grantContentPermission($this, 'work_instructions.read');
    $foreign = TenantKey::create(TenantKey::generateEnvelopeKeys());
    contentTemplate($foreign->id, ['de' => ['title' => 'Foreign', 'body' => 'Secret']]);
    $time = now()->subHour()->startOfSecond();
    foreach (range(1, 17) as $index) {
        $template = contentTemplate($this->tenant->id, ['en' => ['title' => "English {$index}", 'body' => "Body {$index}"]]);
        $template->forceFill(['created_at' => $time->copy()->addSeconds($index)])->save();
    }
    $expected = WorkInstructionTemplate::query()->where('tenant_id', $this->tenant->id)
        ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->pluck('id')->all();

    DB::enableQueryLog();
    $response = $this->withToken($this->token)->getJson('/v1/work-instruction-templates?locale=de');
    $queries = DB::getQueryLog();

    $response->assertOk()->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 17)->assertJsonPath('data.0.localized.locale', 'en')
        ->assertJsonPath('data.0.localized.fallback_used', true);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($expected)
        ->and(count(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'work_instruction_template_translations'))))->toBe(1);
});

test('content lists reject invalid pagination locale and unsupported query parameters', function (string $path, string $query): void {
    grantContentPermission($this, 'work_instructions.read');
    $this->withToken($this->token)->getJson("{$path}?{$query}")->assertUnprocessable();
})->with([
    ['/v1/work-instruction-templates', 'page=0'], ['/v1/work-instruction-templates', 'per_page=101'],
    ['/v1/work-instruction-templates', 'locale=fr'], ['/v1/work-instruction-templates', 'search=x'],
    ['/v1/standard-blocks', 'page=nope'], ['/v1/standard-blocks', 'per_page=0'],
    ['/v1/standard-blocks', 'locale=fr'], ['/v1/standard-blocks', 'locked=true'],
]);

test('template creation accepts each supported complete snapshot and derives tenant ownership', function (array $translations): void {
    grantContentPermission($this, 'work_instructions.create');
    $response = $this->withToken($this->token)->postJson('/v1/work-instruction-templates', contentTranslations($translations));

    $response->assertCreated()->assertJsonPath('data.translations', $translations)
        ->assertJsonMissingPath('data.tenant_id')->assertJsonMissingPath('data.translations.de.id');
    expect(array_keys($response->json('data')))->toBe(['id', 'translations', 'localized', 'created_at', 'updated_at'])
        ->and(WorkInstructionTemplate::query()->sole()->tenant_id)->toBe($this->tenant->id)
        ->and(WorkInstructionTemplateTranslation::query()->count())->toBe(count($translations));
})->with([
    'de only' => [['de' => ['title' => 'Deutsch', 'body' => 'Inhalt']]],
    'en only' => [['en' => ['title' => 'English', 'body' => 'Content']]],
    'bilingual' => [[
        'de' => ['title' => 'Deutsch', 'body' => 'Inhalt'],
        'en' => ['title' => 'English', 'body' => 'Content'],
    ]],
]);

test('template writes enforce the exact closed nonblank snapshot contract', function (array $payload, string $field): void {
    grantContentPermission($this, 'work_instructions.create');
    $this->withToken($this->token)->postJson('/v1/work-instruction-templates', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing translations' => [[], 'translations'],
    'empty translations' => [['translations' => []], 'translations'],
    'unknown locale' => [['translations' => ['fr' => ['title' => 'Titre', 'body' => 'Corps']]], 'translations'],
    'blank title' => [contentTranslations(['de' => ['title' => " \n", 'body' => 'Body']]), 'translations.de.title'],
    'long title' => [contentTranslations(['de' => ['title' => str_repeat('x', 256), 'body' => 'Body']]), 'translations.de.title'],
    'blank body' => [contentTranslations(['de' => ['title' => 'Title', 'body' => " \t"]]), 'translations.de.body'],
    'nested locale' => [['translations' => ['de' => ['title' => 'Title', 'body' => 'Body', 'locale' => 'de']]], 'translations.de'],
    'unknown nested' => [['translations' => ['de' => ['title' => 'Title', 'body' => 'Body', 'category' => 'Safety']]], 'translations.de'],
    'server id' => [contentTranslations() + ['id' => (string) Str::uuid()], 'id'],
    'server tenant' => [contentTranslations() + ['tenant_id' => 999], 'tenant_id'],
    'locked' => [contentTranslations() + ['locked' => true], 'locked'],
    'category' => [contentTranslations() + ['category' => 'Safety'], 'category'],
]);

test('template create query parameters are rejected and create does not imply update', function (): void {
    grantContentPermission($this, 'work_instructions.create');
    $this->withToken($this->token)->postJson('/v1/work-instruction-templates?locale=de', contentTranslations())
        ->assertUnprocessable();
    $template = contentTemplate($this->tenant->id, ['de' => ['title' => 'Old', 'body' => 'Old']]);
    $this->withToken($this->token)->putJson('/v1/work-instruction-templates/'.$template->id, contentTranslations())
        ->assertForbidden();
});

test('template create is atomic when any intended translation cannot persist', function (): void {
    grantContentPermission($this, 'work_instructions.create');
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION reject_english_template_translation() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF NEW.locale = 'en' THEN RAISE EXCEPTION 'injected persistence failure'; END IF;
            RETURN NEW;
        END; $$;
        CREATE TRIGGER reject_english_template_translation BEFORE INSERT
        ON work_instruction_template_translations FOR EACH ROW
        EXECUTE FUNCTION reject_english_template_translation();
        SQL);

    $this->withToken($this->token)->postJson('/v1/work-instruction-templates', contentTranslations())
        ->assertStatus(500)->assertExactJson(['message' => 'Internal server error', 'code' => 'INTERNAL_SERVER_ERROR']);
    expect(WorkInstructionTemplate::query()->count())->toBe(0)
        ->and(WorkInstructionTemplateTranslation::query()->count())->toBe(0);
});

test('template inspect is tenant safe validates identifiers and provides deterministic locale selection', function (string $kind): void {
    grantContentPermission($this, 'work_instructions.read');
    $local = contentTemplate($this->tenant->id, ['de' => ['title' => 'Deutsch', 'body' => 'Inhalt']]);
    $id = match ($kind) {
        'local' => $local->id,
        'foreign' => contentTemplate(TenantKey::create(TenantKey::generateEnvelopeKeys())->id, ['en' => ['title' => 'Secret', 'body' => 'Secret']])->id,
        'missing' => (string) Str::uuid(),
        default => 'not-a-uuid',
    };
    $response = $this->withToken($this->token)->getJson("/v1/work-instruction-templates/{$id}?locale=en");
    if ($kind === 'local') {
        $response->assertOk()->assertJsonPath('data.localized', [
            'locale' => 'de', 'title' => 'Deutsch', 'body' => 'Inhalt', 'fallback_used' => true,
        ]);
    } elseif ($kind === 'malformed') {
        $response->assertUnprocessable()->assertJsonValidationErrors('work_instruction_template');
    } else {
        $response->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    }
})->with(['local', 'foreign', 'missing', 'malformed']);

test('explicit locale overrides the established preferred locale while omission uses it', function (): void {
    grantContentPermission($this, 'work_instructions.read');
    $this->actor->forceFill(['preferred_locale' => 'de'])->save();
    $template = contentTemplate($this->tenant->id, [
        'de' => ['title' => 'Deutsch', 'body' => 'Inhalt'],
        'en' => ['title' => 'English', 'body' => 'Content'],
    ]);

    $this->withToken($this->token)->withHeader('Accept-Language', 'en')
        ->getJson('/v1/work-instruction-templates/'.$template->id)
        ->assertJsonPath('data.localized.locale', 'de')->assertJsonPath('data.localized.fallback_used', false);
    $this->withToken($this->token)->getJson('/v1/work-instruction-templates/'.$template->id.'?locale=en')
        ->assertJsonPath('data.localized.locale', 'en')->assertJsonPath('data.localized.fallback_used', false);
});

test('PUT replaces the complete snapshot including deletion addition and returned localization', function (): void {
    grantContentPermission($this, 'work_instructions.update');
    $template = contentTemplate($this->tenant->id, [
        'de' => ['title' => 'Alt', 'body' => 'Alt'], 'en' => ['title' => 'Old', 'body' => 'Old'],
    ]);
    $deOnly = ['de' => ['title' => 'Neu', 'body' => 'Neuer Inhalt']];
    $this->withToken($this->token)->putJson('/v1/work-instruction-templates/'.$template->id, contentTranslations($deOnly))
        ->assertOk()->assertJsonPath('data.translations', $deOnly);
    expect($template->translations()->get()->map(fn ($translation): string => $translation->locale->value)->all())->toBe(['de']);

    $bilingual = ['de' => ['title' => 'Noch neuer', 'body' => 'D'], 'en' => ['title' => 'Added', 'body' => 'E']];
    $this->withToken($this->token)->putJson('/v1/work-instruction-templates/'.$template->id, contentTranslations($bilingual))
        ->assertOk()->assertJsonPath('data.translations', $bilingual);
});

test('PUT validates complete snapshots query parameters and tenant-scoped targets', function (string $case): void {
    grantContentPermission($this, 'work_instructions.update');
    $id = contentTemplate($this->tenant->id, ['de' => ['title' => 'Old', 'body' => 'Old']])->id;
    $path = "/v1/work-instruction-templates/{$id}";
    $payload = contentTranslations();
    if ($case === 'empty') {
        $payload = ['translations' => []];
    } elseif ($case === 'unknown') {
        $payload['unknown'] = true;
    } elseif ($case === 'query') {
        $path .= '?locale=de';
    } elseif ($case === 'malformed') {
        $path = '/v1/work-instruction-templates/not-a-uuid';
    } elseif ($case === 'foreign') {
        $path = '/v1/work-instruction-templates/'.contentTemplate(
            TenantKey::create(TenantKey::generateEnvelopeKeys())->id,
            ['de' => ['title' => 'Secret', 'body' => 'Secret']],
        )->id;
    }
    $response = $this->withToken($this->token)->putJson($path, $payload);
    match ($case) {
        'foreign' => $response->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']),
        'malformed' => $response->assertUnprocessable()->assertJsonValidationErrors('work_instruction_template'),
        default => $response->assertUnprocessable(),
    };
})->with(['empty', 'unknown', 'query', 'malformed', 'foreign']);

test('a failed complete replacement preserves the old snapshot', function (): void {
    grantContentPermission($this, 'work_instructions.update');
    $old = ['de' => ['title' => 'Old German', 'body' => 'D'], 'en' => ['title' => 'Old English', 'body' => 'E']];
    $template = contentTemplate($this->tenant->id, $old);
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION reject_new_template_translation() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF NEW.title = 'Rejected' THEN RAISE EXCEPTION 'injected replacement failure'; END IF;
            RETURN NEW;
        END; $$;
        CREATE TRIGGER reject_new_template_translation BEFORE INSERT
        ON work_instruction_template_translations FOR EACH ROW
        EXECUTE FUNCTION reject_new_template_translation();
        SQL);

    $this->withToken($this->token)->putJson('/v1/work-instruction-templates/'.$template->id, contentTranslations([
        'de' => ['title' => 'Accepted first', 'body' => 'D'], 'en' => ['title' => 'Rejected', 'body' => 'E'],
    ]))->assertStatus(500)->assertExactJson(['message' => 'Internal server error', 'code' => 'INTERNAL_SERVER_ERROR']);
    expect($template->translations()->orderBy('locale')->get()->mapWithKeys(fn ($translation): array => [
        $translation->locale->value => ['title' => $translation->title, 'body' => $translation->body],
    ])->all())->toBe($old);
});

test('persisted templates without translations fail closed', function (): void {
    grantContentPermission($this, 'work_instructions.read');
    $template = WorkInstructionTemplate::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->withToken($this->token)->getJson('/v1/work-instruction-templates/'.$template->id)
        ->assertStatus(500)->assertExactJson(['message' => 'Internal server error', 'code' => 'INTERNAL_SERVER_ERROR']);
});

test('standard blocks are global immutable paginated ordered localized resources', function (): void {
    grantContentPermission($this, 'work_instructions.read');
    $time = now()->subHour()->startOfSecond();
    foreach (range(1, 17) as $index) {
        $block = standardBlock(['de' => ['title' => "Block {$index}", 'body' => 'Inhalt']]);
        $block->forceFill(['created_at' => $time->copy()->addSeconds($index)])->save();
    }
    $expected = WorkInstructionStandardBlock::query()->orderByDesc('created_at')->orderByDesc('id')->limit(2)->pluck('id')->all();
    $response = $this->withToken($this->token)->getJson('/v1/standard-blocks?locale=en&per_page=2');
    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 17)
        ->assertJsonPath('data.0.locked', true)->assertJsonPath('data.0.localized.locale', 'de')
        ->assertJsonPath('data.0.localized.fallback_used', true);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($expected)
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'key', 'locked', 'localized', 'created_at', 'updated_at'])
        ->and($response->json('data.0'))->not->toHaveKeys(['tenant_id', 'translations', 'category']);

    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $other = User::factory()->create(['tenant_id' => $otherTenant->id]);
    givePermissionWithTenant($other, $otherTenant->id, 'work_instructions.read');
    $otherToken = $other->createToken('other-content')->plainTextToken;
    $this->withToken($otherToken)->getJson('/v1/standard-blocks')->assertJsonPath('meta.total', 17);
});

test('standard block inspect validates ids localizes and returns neutral missing behavior', function (string $kind): void {
    grantContentPermission($this, 'work_instructions.read');
    $block = standardBlock(['en' => ['title' => 'English', 'body' => 'Content']]);
    $id = match ($kind) {
        'found' => $block->id, 'missing' => (string) Str::uuid(), default => 'bad-id'
    };
    $response = $this->withToken($this->token)->getJson("/v1/standard-blocks/{$id}?locale=de");
    match ($kind) {
        'found' => $response->assertOk()->assertJsonPath('data.localized', [
            'locale' => 'en', 'title' => 'English', 'body' => 'Content', 'fallback_used' => true,
        ])->assertJsonPath('data.locked', true),
        'missing' => $response->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']),
        default => $response->assertUnprocessable()->assertJsonValidationErrors('standard_block'),
    };
})->with(['found', 'missing', 'malformed']);

test('persisted standard blocks without translations fail closed', function (): void {
    grantContentPermission($this, 'work_instructions.read');
    $block = WorkInstructionStandardBlock::factory()->create();
    $this->withToken($this->token)->getJson('/v1/standard-blocks/'.$block->id)
        ->assertStatus(500)->assertExactJson(['message' => 'Internal server error', 'code' => 'INTERNAL_SERVER_ERROR']);
});
