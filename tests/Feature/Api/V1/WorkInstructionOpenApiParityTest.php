<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\ContentLocale;
use App\Enums\WorkInstructionStatus;
use App\Http\Controllers\Api\V1\WorkInstructionController;
use App\Http\Requests\Api\V1\WorkInstructionRequest;
use App\Http\Resources\Api\V1\WorkInstructionResource;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('implementation pins the accepted Contracts Work Instruction surface', function (): void {
    expect(WorkInstructionRequest::AUTHORITATIVE_CONTRACTS_SHA)
        ->toBe('074d23437437dad5457d3a2418d814ddd6baee03')
        ->and(WorkInstructionRequest::CREATE_FIELDS)
        ->toBe(['instruction_number', 'title', 'body', 'locale'])
        ->and(WorkInstructionRequest::UPDATE_FIELDS)
        ->toBe(['title', 'body', 'locale'])
        ->and(WorkInstructionRequest::OPERATION_IDS)
        ->toBe([
            'GET v1/work-instructions' => 'listWorkInstructions',
            'POST v1/work-instructions' => 'createWorkInstruction',
            'GET v1/work-instructions/{workInstruction}' => 'getWorkInstruction',
            'PATCH v1/work-instructions/{workInstruction}' => 'updateWorkInstruction',
            'POST v1/work-instructions/{workInstruction}/submit-for-review' => 'submitWorkInstructionForReview',
            'POST v1/work-instructions/{workInstruction}/publish' => 'publishWorkInstruction',
            'POST v1/work-instructions/{workInstruction}/archive' => 'archiveWorkInstruction',
        ])
        ->and(array_column(WorkInstructionStatus::cases(), 'value'))
        ->toBe(['draft', 'in_review', 'published', 'archived'])
        ->and(array_column(ContentLocale::cases(), 'value'))->toBe(['de', 'en'])
        ->and(WorkInstructionResource::FIELDS)->toBe([
            'id', 'instruction_number', 'title', 'body', 'locale', 'status',
            'published_at', 'published_by_user_id', 'archived_at', 'archived_by_user_id',
            'created_at', 'updated_at',
        ]);
});

test('the API exposes exactly seven forward-only Work Instruction operations', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/work-instructions'))
        ->flatMap(fn ($route): array => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): array => [
                'signature' => $method.' '.$route->uri(),
                'action' => $route->getActionName(),
                'operation_id' => $route->getName(),
                'permission' => collect($route->gatherMiddleware())
                    ->first(fn (string $middleware): bool => str_starts_with($middleware, 'permission:work_instructions.')),
            ])->all())
        ->keyBy('signature')
        ->sortKeys();

    expect($routes->keys()->all())->toBe([
        'GET v1/work-instructions',
        'GET v1/work-instructions/{workInstruction}',
        'PATCH v1/work-instructions/{workInstruction}',
        'POST v1/work-instructions',
        'POST v1/work-instructions/{workInstruction}/archive',
        'POST v1/work-instructions/{workInstruction}/publish',
        'POST v1/work-instructions/{workInstruction}/submit-for-review',
    ]);

    $expected = [
        'GET v1/work-instructions' => ['index', 'listWorkInstructions', 'work_instructions.read'],
        'GET v1/work-instructions/{workInstruction}' => ['show', 'getWorkInstruction', 'work_instructions.read'],
        'PATCH v1/work-instructions/{workInstruction}' => ['update', 'updateWorkInstruction', 'work_instructions.update'],
        'POST v1/work-instructions' => ['store', 'createWorkInstruction', 'work_instructions.create'],
        'POST v1/work-instructions/{workInstruction}/archive' => ['archive', 'archiveWorkInstruction', 'work_instructions.archive'],
        'POST v1/work-instructions/{workInstruction}/publish' => ['publish', 'publishWorkInstruction', 'work_instructions.publish'],
        'POST v1/work-instructions/{workInstruction}/submit-for-review' => ['submitForReview', 'submitWorkInstructionForReview', 'work_instructions.update'],
    ];

    foreach ($expected as $signature => [$method, $operationId, $permission]) {
        expect($routes[$signature]['action'])->toBe(WorkInstructionController::class.'@'.$method)
            ->and($routes[$signature]['operation_id'])->toBe($operationId)
            ->and($routes[$signature]['permission'])->toBe('permission:'.$permission);
    }
});

test('permission catalog contains the accepted lifecycle capabilities without aliases', function (): void {
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $permissions = Permission::query()
        ->where('name', 'like', 'work_instructions.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($permissions)->toBe([
        'work_instructions.acknowledge',
        'work_instructions.archive',
        'work_instructions.create',
        'work_instructions.publish',
        'work_instructions.read',
        'work_instructions.update',
        'work_instructions.view_acknowledgments',
    ])->and(Permission::query()->whereIn('name', [
        'work_instructions.delete',
        'work_instructions.review',
    ])->exists())->toBeFalse()
        ->and(Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'work_instructions.archive'))->exists())
        ->toBeFalse();
});
