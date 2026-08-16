<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Http\Requests\Api\IndexOrganizationalUnitRequest;
use Illuminate\Support\Facades\Validator;

it('accepts textual boolean organizational-unit status filters', function (string $filter, string $value): void {
    $request = new IndexOrganizationalUnitRequest;

    $validator = Validator::make([
        $filter => $value,
    ], $request->rules());

    expect($validator->passes())->toBeTrue();
})->with([
    'active true' => ['is_active', 'true'],
    'active false' => ['is_active', 'false'],
    'assignable true' => ['is_assignable', 'true'],
    'assignable false' => ['is_assignable', 'false'],
]);

it('rejects invalid organizational-unit status filters', function (string $filter, string $value): void {
    $request = new IndexOrganizationalUnitRequest;

    $validator = Validator::make([
        $filter => $value,
    ], $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has($filter))->toBeTrue();
})->with([
    'active unrelated text' => ['is_active', 'not-a-boolean'],
    'active alternate boolean spelling' => ['is_active', 'on'],
    'active numeric variant' => ['is_active', '01'],
    'active decimal variant' => ['is_active', '1.0'],
    'assignable unrelated text' => ['is_assignable', 'not-a-boolean'],
    'assignable alternate boolean spelling' => ['is_assignable', 'on'],
    'assignable numeric variant' => ['is_assignable', '01'],
    'assignable decimal variant' => ['is_assignable', '1.0'],
]);
