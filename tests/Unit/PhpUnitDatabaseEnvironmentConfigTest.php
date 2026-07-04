<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

/**
 * @return array{value: string, force: string}
 */
function phpunitEnvSetting(string $name): array
{
    $configuration = simplexml_load_file(base_path('phpunit.xml'));

    expect($configuration)->toBeInstanceOf(SimpleXMLElement::class);

    $envNodes = $configuration->xpath(sprintf('/phpunit/php/env[@name="%s"]', $name));

    expect($envNodes)->not->toBeFalse()
        ->and($envNodes)->toHaveCount(1);

    /** @var SimpleXMLElement $envNode */
    $envNode = $envNodes[0];

    return [
        'value' => (string) $envNode['value'],
        'force' => (string) $envNode['force'],
    ];
}

test('phpunit forces postgres test environment overrides before early bootstrap code runs', function (): void {
    expect(phpunitEnvSetting('DB_CONNECTION'))->toBe([
        'value' => 'pgsql',
        'force' => 'true',
    ])->and(phpunitEnvSetting('DB_DATABASE'))->toBe([
        'value' => 'testing',
        'force' => 'true',
    ]);
});
