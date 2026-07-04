<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('non-eu work permit core migration applies on sqlite-backed setups', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $contextDirectory = $repositoryRoot.'/.context';
    $databasePath = $contextDirectory.'/non-eu-work-permit-migration.sqlite';

    if (! is_dir($contextDirectory)) {
        mkdir($contextDirectory, 0777, true);
    }

    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    touch($databasePath);

    $process = new Process(
        ['php', 'artisan', 'migrate:fresh', '--force'],
        $repositoryRoot,
        [
            'APP_NAME' => 'SecPal',
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:nRWNo2CgugcDYn5VJsEzigv2nowyJLSArqfRhlB+USo=',
            'APP_DEBUG' => 'true',
            'APP_URL' => 'https://api.secpal.dev',
            'FRONTEND_URL' => 'https://app.secpal.dev',
            'LOG_CHANNEL' => 'stack',
            'LOG_LEVEL' => 'debug',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $databasePath,
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'file',
            'MAIL_MAILER' => 'array',
            'ADDRESS_DATA_IMPORT_ON_SETUP' => 'false',
            'CORS_ALLOWED_ORIGINS' => 'https://app.secpal.dev',
        ],
    );
    $process->run();
    $output = $process->getErrorOutput().$process->getOutput();

    if (is_file($databasePath)) {
        unlink($databasePath);
    }

    expect(preg_match(
        '/2026_04_12_150000_add_non_eu_work_permit_core_to_employees_table\s+\d+\.\d+ms DONE/',
        $output,
    ))->toBe(1, $output)
        ->and(preg_match(
            '/2026_04_12_150000_add_non_eu_work_permit_core_to_employees_table\s+\d+\.\d+ms FAIL/',
            $output,
        ))->toBe(0, $output);
});
