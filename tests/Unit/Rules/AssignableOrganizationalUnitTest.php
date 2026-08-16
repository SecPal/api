<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Rules\AssignableOrganizationalUnit;

test('skips assignability lookup without a scalar organizational unit and tenant context', function (mixed $tenantId, mixed $organizationalUnitId): void {
    $messages = [];

    (new AssignableOrganizationalUnit($tenantId))->validate(
        'organizational_unit_id',
        $organizationalUnitId,
        function (string $message) use (&$messages): void {
            $messages[] = $message;
        },
    );

    expect($messages)->toBe([]);
})->with([
    'missing organizational unit' => [1, null],
    'non-scalar organizational unit' => [1, ['unit']],
    'missing tenant' => [null, '550e8400-e29b-41d4-a716-446655440000'],
]);
