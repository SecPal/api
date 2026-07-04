<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('non-eu work permit core migration applies on sqlite-backed setups', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $databasePath = createTemporarySqliteDatabase('non-eu-work-permit-migration');

    $process = new Process(
        ['php', 'artisan', 'migrate:fresh', '--force'],
        $repositoryRoot,
        sqliteProcessEnvironment($databasePath),
    );
    try {
        $process->run();
        $output = $process->getErrorOutput().$process->getOutput();
    } finally {
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
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

test('non-eu work permit core migration rewrites existing sqlite legacy permit values', function (): void {
    $databasePath = createTemporarySqliteDatabase('non-eu-work-permit-migration-seeded');

    try {
        $process = new Process(
            ['php'],
            dirname(__DIR__, 2),
            ['SQLITE_DATABASE' => $databasePath],
            seededSqliteMigrationScript(),
        );
        $process->run();

        $output = $process->getErrorOutput().$process->getOutput();
        $result = json_decode(trim($process->getOutput()), true);

        expect($process->isSuccessful())->toBeTrue($output)
            ->and($result)->toBe([
                'accepts_invalid_type' => false,
                'accepts_invalid_status' => false,
                'work_permit_types' => [
                    '11111111-1111-1111-1111-111111111111' => 'permanent',
                    '22222222-2222-2222-2222-222222222222' => 'temporary',
                ],
            ]);
    } finally {
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
});

test('non-eu work permit core migration rolls back existing sqlite permit values', function (): void {
    $databasePath = createTemporarySqliteDatabase('non-eu-work-permit-migration-rollback');

    try {
        $process = new Process(
            ['php'],
            dirname(__DIR__, 2),
            ['SQLITE_DATABASE' => $databasePath],
            seededSqliteRollbackScript(),
        );
        $process->run();

        $output = $process->getErrorOutput().$process->getOutput();
        $result = json_decode(trim($process->getOutput()), true);

        expect($process->isSuccessful())->toBeTrue($output)
            ->and($result)->toBe([
                'accepts_invalid_type' => false,
                'accepts_invalid_status' => false,
                'work_permit_types' => [
                    '11111111-1111-1111-1111-111111111111' => 'unlimited',
                    '22222222-2222-2222-2222-222222222222' => 'limited',
                    '33333333-3333-3333-3333-333333333333' => 'limited',
                ],
            ]);
    } finally {
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
});

function createTemporarySqliteDatabase(string $name): string
{
    $temporaryPath = tempnam(sys_get_temp_dir(), $name.'-');

    if ($temporaryPath === false) {
        throw new RuntimeException('Unable to allocate a temporary SQLite database file.');
    }

    if (! chmod($temporaryPath, 0600)) {
        @unlink($temporaryPath);

        throw new RuntimeException('Unable to restrict permissions on the temporary SQLite database file.');
    }

    return $temporaryPath;
}

/**
 * @return array<string, string>
 */
function sqliteProcessEnvironment(string $databasePath): array
{
    return [
        'APP_NAME' => 'SecPal',
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
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
    ];
}

function seededSqliteMigrationScript(): string
{
    return <<<'PHP'
<?php

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => getenv('SQLITE_DATABASE'),
    'database.connections.sqlite.foreign_key_constraints' => false,
]);

DB::purge('sqlite');
DB::setDefaultConnection('sqlite');

Schema::create('tenant_keys', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->binary('dek_wrapped')->nullable();
        $table->binary('dek_nonce')->nullable();
        $table->binary('idx_wrapped')->nullable();
        $table->binary('idx_nonce')->nullable();
        $table->integer('key_version')->default(1);
        $table->timestamp('created_at')->nullable();
});

Schema::create('employees', function (Illuminate\Database\Schema\Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->unsignedBigInteger('tenant_id');
    $table->enum('status', ['applicant', 'pre_contract', 'active', 'on_leave', 'terminated'])->default('applicant');
    $table->enum('work_permit_type', ['unlimited', 'limited', 'none'])->default('none');
    $table->string('work_permit_number')->nullable();
    $table->date('work_permit_expiry')->nullable();
});

DB::table('tenant_keys')->insert(['id' => 1]);
DB::table('employees')->insert([
    [
        'id' => '11111111-1111-1111-1111-111111111111',
        'tenant_id' => 1,
        'status' => 'applicant',
        'work_permit_type' => 'unlimited',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ],
    [
        'id' => '22222222-2222-2222-2222-222222222222',
        'tenant_id' => 1,
        'status' => 'active',
        'work_permit_type' => 'limited',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ],
]);

$migration = require 'database/migrations/2026_04_12_150000_add_non_eu_work_permit_core_to_employees_table.php';
$migration->up();

$acceptsInvalidType = true;

try {
    DB::table('employees')->insert([
        'id' => '33333333-3333-3333-3333-333333333333',
        'tenant_id' => 1,
        'status' => 'active',
        'work_permit_type' => 'invalid',
        'work_permit_number_enc' => null,
        'work_permit_copy_path' => null,
        'work_permit_issued_by' => null,
        'work_permit_copy_deleted_at' => null,
        'work_permit_expiry' => null,
    ]);
} catch (Illuminate\Database\QueryException) {
    $acceptsInvalidType = false;
}

$acceptsInvalidStatus = true;

try {
    DB::table('employees')->insert([
        'id' => '44444444-4444-4444-4444-444444444444',
        'tenant_id' => 1,
        'status' => 'invalid',
        'work_permit_type' => 'permanent',
        'work_permit_number_enc' => null,
        'work_permit_copy_path' => null,
        'work_permit_issued_by' => null,
        'work_permit_copy_deleted_at' => null,
        'work_permit_expiry' => null,
    ]);
} catch (Illuminate\Database\QueryException) {
    $acceptsInvalidStatus = false;
}

echo json_encode([
    'accepts_invalid_type' => $acceptsInvalidType,
    'accepts_invalid_status' => $acceptsInvalidStatus,
    'work_permit_types' => DB::table('employees')->pluck('work_permit_type', 'id')->all(),
], JSON_THROW_ON_ERROR);
PHP;
}

function seededSqliteRollbackScript(): string
{
    return <<<'PHP'
<?php

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => getenv('SQLITE_DATABASE'),
    'database.connections.sqlite.foreign_key_constraints' => false,
]);

DB::purge('sqlite');
DB::setDefaultConnection('sqlite');

Schema::create('tenant_keys', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->binary('dek_wrapped')->nullable();
        $table->binary('dek_nonce')->nullable();
        $table->binary('idx_wrapped')->nullable();
        $table->binary('idx_nonce')->nullable();
        $table->integer('key_version')->default(1);
        $table->timestamp('created_at')->nullable();
});

Schema::create('employees', function (Illuminate\Database\Schema\Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->unsignedBigInteger('tenant_id');
    $table->enum('status', ['applicant', 'pre_contract', 'active', 'on_leave', 'terminated'])->default('applicant');
    $table->enum('work_permit_type', ['none', 'temporary', 'permanent', 'blue_card', 'seasonal', 'student'])->default('none');
    $table->text('work_permit_number_enc')->nullable();
    $table->string('work_permit_copy_path')->nullable();
    $table->string('work_permit_issued_by', 255)->nullable();
    $table->timestamp('work_permit_copy_deleted_at')->nullable();
    $table->date('work_permit_expiry')->nullable();
});

Schema::table('employees', function (Illuminate\Database\Schema\Blueprint $table): void {
    $table->index('work_permit_expiry', 'idx_employees_work_permit_expiry');
});

DB::table('tenant_keys')->insert(['id' => 1]);
DB::table('employees')->insert([
    [
        'id' => '11111111-1111-1111-1111-111111111111',
        'tenant_id' => 1,
        'status' => 'applicant',
        'work_permit_type' => 'permanent',
        'work_permit_number_enc' => null,
        'work_permit_copy_path' => null,
        'work_permit_issued_by' => null,
        'work_permit_copy_deleted_at' => null,
        'work_permit_expiry' => null,
    ],
    [
        'id' => '22222222-2222-2222-2222-222222222222',
        'tenant_id' => 1,
        'status' => 'active',
        'work_permit_type' => 'temporary',
        'work_permit_number_enc' => null,
        'work_permit_copy_path' => null,
        'work_permit_issued_by' => null,
        'work_permit_copy_deleted_at' => null,
        'work_permit_expiry' => null,
    ],
    [
        'id' => '33333333-3333-3333-3333-333333333333',
        'tenant_id' => 1,
        'status' => 'on_leave',
        'work_permit_type' => 'blue_card',
        'work_permit_number_enc' => null,
        'work_permit_copy_path' => null,
        'work_permit_issued_by' => null,
        'work_permit_copy_deleted_at' => null,
        'work_permit_expiry' => null,
    ],
]);

$migration = require 'database/migrations/2026_04_12_150000_add_non_eu_work_permit_core_to_employees_table.php';
$migration->down();

$acceptsInvalidType = true;

try {
    DB::table('employees')->insert([
        'id' => '44444444-4444-4444-4444-444444444444',
        'tenant_id' => 1,
        'status' => 'active',
        'work_permit_type' => 'temporary',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ]);
} catch (Illuminate\Database\QueryException) {
    $acceptsInvalidType = false;
}

$acceptsInvalidStatus = true;

try {
    DB::table('employees')->insert([
        'id' => '55555555-5555-5555-5555-555555555555',
        'tenant_id' => 1,
        'status' => 'invalid',
        'work_permit_type' => 'limited',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ]);
} catch (Illuminate\Database\QueryException) {
    $acceptsInvalidStatus = false;
}

echo json_encode([
    'accepts_invalid_type' => $acceptsInvalidType,
    'accepts_invalid_status' => $acceptsInvalidStatus,
    'work_permit_types' => DB::table('employees')->pluck('work_permit_type', 'id')->all(),
], JSON_THROW_ON_ERROR);
PHP;
}
