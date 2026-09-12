<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Requests\Api\V1\CustomerTransactionalEditRequest;
use Illuminate\Support\Facades\Route;

test('transactional edit implementation is bound to the accepted contracts delivery', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->uri() === 'v1/customers/{customer}/transactional-edit'
            && in_array('PUT', $candidate->methods(), true));

    expect(CustomerTransactionalEditRequest::AUTHORITATIVE_CONTRACTS_SHA)
        ->toBe('6639eca8a09a1a563a861724e4fb230c93d8a565')
        ->and($route)->not->toBeNull()
        ->and($route->getActionName())->toBe(CustomerController::class.'@transactionalEdit')
        ->and($route->wheres['customer'] ?? null)->not->toBeNull();
});
