<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\ContentLocale;
use App\Exceptions\WorkInstructionContentIntegrityException;
use App\Http\Requests\Api\V1\WorkInstructionContentRequest;
use App\Http\Resources\Api\V1\WorkInstructionStandardBlockResource;
use App\Http\Resources\Api\V1\WorkInstructionTemplateResource;
use App\Models\WorkInstructionStandardBlock;
use App\Models\WorkInstructionTemplate;
use App\Services\WorkInstructionContentLocalizer;

test('implementation pins the accepted Contracts content library surface', function (): void {
    expect(WorkInstructionContentRequest::AUTHORITATIVE_CONTRACTS_SHA)
        ->toBe('21c1ba03ed12c17a73a3d4298b1140b20de3237a')
        ->and(WorkInstructionContentRequest::OPERATION_IDS)->toBe([
            'GET v1/work-instruction-templates' => 'listWorkInstructionTemplates',
            'POST v1/work-instruction-templates' => 'createWorkInstructionTemplate',
            'GET v1/work-instruction-templates/{workInstructionTemplate}' => 'getWorkInstructionTemplate',
            'PUT v1/work-instruction-templates/{workInstructionTemplate}' => 'replaceWorkInstructionTemplateTranslations',
            'GET v1/standard-blocks' => 'listWorkInstructionStandardBlocks',
            'GET v1/standard-blocks/{standardBlock}' => 'getWorkInstructionStandardBlock',
        ])
        ->and(array_column(ContentLocale::cases(), 'value'))->toBe(['de', 'en'])
        ->and(WorkInstructionTemplateResource::FIELDS)
        ->toBe(['id', 'translations', 'localized', 'created_at', 'updated_at'])
        ->and(WorkInstructionStandardBlockResource::FIELDS)
        ->toBe(['id', 'key', 'locked', 'localized', 'created_at', 'updated_at']);
});

test('accepted content responses contain no conflict status', function (): void {
    expect(WorkInstructionContentRequest::RESPONSE_STATUSES)->toBe([
        'GET v1/work-instruction-templates' => [200, 401, 403, 422, 429, 500],
        'POST v1/work-instruction-templates' => [201, 401, 403, 422, 429, 500],
        'GET v1/work-instruction-templates/{workInstructionTemplate}' => [200, 401, 403, 404, 422, 429, 500],
        'PUT v1/work-instruction-templates/{workInstructionTemplate}' => [200, 401, 403, 404, 422, 429, 500],
        'GET v1/standard-blocks' => [200, 401, 403, 422, 429, 500],
        'GET v1/standard-blocks/{standardBlock}' => [200, 401, 403, 404, 422, 429, 500],
    ])->and(collect(WorkInstructionContentRequest::RESPONSE_STATUSES)->flatten()->contains(409))->toBeFalse();
});

test('persistence ownership remains structurally distinct without content inventions', function (): void {
    expect((new WorkInstructionTemplate)->getFillable())->toBe(['tenant_id'])
        ->and((new WorkInstructionStandardBlock)->getFillable())->toBe(['key'])
        ->and((new WorkInstructionStandardBlock)->getAttributes())->not->toHaveKeys(['tenant_id', 'locked'])
        ->and(WorkInstructionTemplateResource::FIELDS)->not->toContain(
            'tenant_id', 'translation_id', 'category', 'is_system_template', 'status',
            'acknowledgments', 'sections',
        )
        ->and(WorkInstructionStandardBlockResource::FIELDS)->not->toContain(
            'tenant_id', 'translations', 'translation_id', 'category', 'status',
            'acknowledgments', 'sections',
        );
});

test('the shared localizer owns exact locale fallback and fail closed behavior', function (): void {
    $localizer = app(WorkInstructionContentLocalizer::class);
    $de = ['de' => ['title' => 'Deutsch', 'body' => 'Inhalt']];

    expect($localizer->select($de, ContentLocale::German))->toBe([
        'locale' => 'de', 'title' => 'Deutsch', 'body' => 'Inhalt', 'fallback_used' => false,
    ])->and($localizer->select($de, ContentLocale::English))->toBe([
        'locale' => 'de', 'title' => 'Deutsch', 'body' => 'Inhalt', 'fallback_used' => true,
    ]);

    expect(fn (): array => $localizer->select([], ContentLocale::English))
        ->toThrow(WorkInstructionContentIntegrityException::class);
});
