<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('remove-admin access level migration applies on sqlite-backed setups', function (): void {
    $databasePath = createRemoveAdminAccessLevelTemporarySqliteDatabase('remove-admin-access-level-migration');

    try {
        $process = new Process(
            ['php'],
            dirname(__DIR__, 2),
            ['SQLITE_DATABASE' => $databasePath],
            seededRemoveAdminAccessLevelSqliteMigrationScript(),
        );
        $process->run();

        $output = $process->getErrorOutput().$process->getOutput();
        $result = json_decode(trim($process->getOutput()), true);

        expect($process->isSuccessful())->toBeTrue($output)
            ->and($result)->toBe([
                'rows' => [['access_level' => 'manage']],
                'table_sql_contains_manage_check' => true,
            ]);
    } finally {
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
});

test('create employee addresses migration applies on sqlite-backed setups', function (): void {
    $databasePath = createRemoveAdminAccessLevelTemporarySqliteDatabase('create-employee-addresses-migration');

    try {
        $process = new Process(
            ['php'],
            dirname(__DIR__, 2),
            ['SQLITE_DATABASE' => $databasePath],
            seededRemoveAdminAccessLevelEmployeeAddressesMigrationScript(),
        );
        $process->run();

        $output = $process->getErrorOutput().$process->getOutput();
        $result = json_decode(trim($process->getOutput()), true);

        expect($process->isSuccessful())->toBeTrue($output)
            ->and($result)->toBe([
                'rejects_invalid_date_range' => true,
                'allows_single_current_address' => true,
                'rejects_second_current_address' => true,
            ]);
    } finally {
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
});

test('sqlite-backed migrate:fresh stops at the PostgreSQL-only domain migration', function (): void {
    $databasePath = createRemoveAdminAccessLevelTemporarySqliteDatabase('sqlite-migrate-fresh');

    try {
        $process = new Process(
            ['php', 'artisan', 'migrate:fresh', '--force'],
            dirname(__DIR__, 2),
            removeAdminAccessLevelFullSqliteMigrationEnvironment($databasePath),
        );
        $process->run();

        $output = $process->getErrorOutput().$process->getOutput();

        expect($process->isSuccessful())->toBeFalse($output)
            ->and($output)->not->toContain('android_enrollment')
            ->and($output)->toContain('2026_07_17_120000_create_legal_entity_domain_model')
            ->and($output)->toContain('requires PostgreSQL');
    } finally {
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
});

function createRemoveAdminAccessLevelTemporarySqliteDatabase(string $name): string
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

function seededRemoveAdminAccessLevelSqliteMigrationScript(): string
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

DB::statement(<<<'SQL'
    CREATE TABLE "user_internal_organizational_scopes" (
        "id" varchar not null,
        "user_id" varchar not null,
        "organizational_unit_id" varchar not null,
        "access_level" varchar check ("access_level" in ('none', 'read', 'write', 'admin', 'manage')) not null default 'read',
        "include_descendants" tinyint(1) not null default '0',
        "created_at" datetime,
        "updated_at" datetime,
        "min_viewable_rank" integer,
        "max_viewable_rank" integer,
        "min_assignable_rank" integer,
        "max_assignable_rank" integer,
        "allow_self_access" tinyint(1) not null default '0',
        primary key ("id")
    )
SQL);

DB::table('user_internal_organizational_scopes')->insert([
    'id' => 'scope-1',
    'user_id' => 'user-1',
    'organizational_unit_id' => 'unit-1',
    'access_level' => 'admin',
    'include_descendants' => false,
    'created_at' => now(),
    'updated_at' => now(),
]);

$migration = require 'database/migrations/2026_04_30_120000_remove_admin_access_level_from_user_internal_organizational_scopes.php';
$migration->up();

$tableSql = DB::selectOne(
    "select sql from sqlite_master where type = 'table' and name = 'user_internal_organizational_scopes'"
)->sql;

echo json_encode([
    'rows' => DB::table('user_internal_organizational_scopes')
        ->select('access_level')
        ->get()
        ->map(fn (object $row): array => ['access_level' => $row->access_level])
        ->all(),
    'table_sql_contains_manage_check' => str_contains(
        $tableSql,
        '"access_level" varchar check ("access_level" in (\'none\', \'read\', \'write\', \'manage\'))'
    ),
], JSON_THROW_ON_ERROR);
PHP;
}

function seededRemoveAdminAccessLevelEmployeeAddressesMigrationScript(): string
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
});

Schema::create('employees', function (Illuminate\Database\Schema\Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
});

DB::table('tenant_keys')->insert(['id' => 1]);
DB::table('employees')->insert([
    'id' => '11111111-1111-1111-1111-111111111111',
    'tenant_id' => 1,
]);

$migration = require 'database/migrations/2026_05_10_120000_create_employee_addresses_table.php';
$migration->up();

$rejectsInvalidDateRange = false;

try {
    DB::table('employee_addresses')->insert([
        'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'employee_id' => '11111111-1111-1111-1111-111111111111',
        'tenant_id' => 1,
        'resided_from' => '2026-02-01',
        'resided_until' => '2026-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
} catch (Throwable) {
    $rejectsInvalidDateRange = true;
}

DB::table('employee_addresses')->insert([
    'id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
    'employee_id' => '11111111-1111-1111-1111-111111111111',
    'tenant_id' => 1,
    'resided_from' => '2025-01-01',
    'resided_until' => null,
    'created_at' => now(),
    'updated_at' => now(),
]);

$rejectsSecondCurrentAddress = false;

try {
    DB::table('employee_addresses')->insert([
        'id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'employee_id' => '11111111-1111-1111-1111-111111111111',
        'tenant_id' => 1,
        'resided_from' => '2026-03-01',
        'resided_until' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
} catch (Throwable) {
    $rejectsSecondCurrentAddress = true;
}

echo json_encode([
    'rejects_invalid_date_range' => $rejectsInvalidDateRange,
    'allows_single_current_address' => DB::table('employee_addresses')->count() === 1,
    'rejects_second_current_address' => $rejectsSecondCurrentAddress,
], JSON_THROW_ON_ERROR);
PHP;
}

/**
 * @return array<string, string>
 */
function removeAdminAccessLevelFullSqliteMigrationEnvironment(string $databasePath): array
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
