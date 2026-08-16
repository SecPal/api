<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

test('employee audit history rollback on sqlite deletes null uploader rows before restoring not-null references', function (): void {
    $databasePath = createEmployeeAuditRollbackTemporarySqliteDatabase('employee-audit-history-rollback');

    try {
        $process = new Process(
            ['php'],
            dirname(__DIR__, 2),
            ['SQLITE_DATABASE' => $databasePath],
            seededEmployeeAuditRollbackSqliteScript(),
        );
        $process->run();

        $output = $process->getErrorOutput().$process->getOutput();
        $result = json_decode(trim($process->getOutput()), true);

        expect($process->isSuccessful())->toBeTrue($output)
            ->and($result)->toBe([
                'employee_documents' => [
                    ['id' => 'document-kept', 'uploaded_by' => 'user-1'],
                ],
                'onboarding_submission_files' => [
                    ['id' => 'file-kept', 'uploaded_by' => 'user-1'],
                ],
                'role_assignments_log' => [
                    ['id' => 'role-kept', 'user_id' => 'user-1'],
                ],
            ]);
    } finally {
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
});

function createEmployeeAuditRollbackTemporarySqliteDatabase(string $name): string
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

function seededEmployeeAuditRollbackSqliteScript(): string
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
    CREATE TABLE "users" (
        "id" varchar not null,
        primary key ("id")
    )
SQL);

DB::statement(<<<'SQL'
    CREATE TABLE "onboarding_form_submissions" (
        "id" varchar not null,
        "reviewed_by" varchar,
        primary key ("id"),
        foreign key("reviewed_by") references "users"("id") on delete set null
    )
SQL);

DB::statement(<<<'SQL'
    CREATE TABLE "onboarding_submission_files" (
        "id" varchar not null,
        "uploaded_by" varchar,
        primary key ("id"),
        foreign key("uploaded_by") references "users"("id") on delete set null
    )
SQL);

DB::statement(<<<'SQL'
    CREATE TABLE "employee_documents" (
        "id" varchar not null,
        "uploaded_by" varchar,
        primary key ("id"),
        foreign key("uploaded_by") references "users"("id") on delete set null
    )
SQL);

DB::statement(<<<'SQL'
    CREATE TABLE "role_assignments_log" (
        "id" varchar not null,
        "user_id" varchar,
        primary key ("id"),
        foreign key("user_id") references "users"("id") on delete set null
    )
SQL);

DB::table('users')->insert([
    'id' => 'user-1',
]);

DB::table('onboarding_form_submissions')->insert([
    'id' => 'submission-kept',
    'reviewed_by' => null,
]);

DB::table('onboarding_submission_files')->insert([
    ['id' => 'file-kept', 'uploaded_by' => 'user-1'],
    ['id' => 'file-deleted', 'uploaded_by' => null],
]);

DB::table('employee_documents')->insert([
    ['id' => 'document-kept', 'uploaded_by' => 'user-1'],
    ['id' => 'document-deleted', 'uploaded_by' => null],
]);

DB::table('role_assignments_log')->insert([
    ['id' => 'role-kept', 'user_id' => 'user-1'],
    ['id' => 'role-deleted', 'user_id' => null],
]);

$migration = require 'database/migrations/2026_06_29_120000_allow_employee_audit_records_to_survive_user_deletion.php';
$migration->down();

echo json_encode([
    'employee_documents' => DB::table('employee_documents')
        ->select('id', 'uploaded_by')
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => [
            'id' => $row->id,
            'uploaded_by' => $row->uploaded_by,
        ])
        ->all(),
    'onboarding_submission_files' => DB::table('onboarding_submission_files')
        ->select('id', 'uploaded_by')
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => [
            'id' => $row->id,
            'uploaded_by' => $row->uploaded_by,
        ])
        ->all(),
    'role_assignments_log' => DB::table('role_assignments_log')
        ->select('id', 'user_id')
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => [
            'id' => $row->id,
            'user_id' => $row->user_id,
        ])
        ->all(),
], JSON_THROW_ON_ERROR);
PHP;
}
