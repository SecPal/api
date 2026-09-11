<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\ContentLocale;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\WorkInstructionTemplate;
use App\Models\WorkInstructionTemplateTranslation;
use App\Services\WorkInstructionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

function refreshTemplateConcurrencyDatabase(): void
{
    $token = ParallelTesting::token();
    if ($token !== false && $token !== '') {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $suffix = '_test_'.$token;
        if (! str_ends_with($database, $suffix)) {
            $root = preg_replace('/_test_\d+$/', '', $database);
            if (is_string($root) && $root !== '') {
                config()->set("database.connections.{$connection}.database", $root.$suffix);
                config()->set("database.connections.{$connection}.url", null);
            }
        }
    }
    DB::purge();
    DB::reconnect();
    Artisan::call('migrate:fresh', ['--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

beforeEach(function (): void {
    refreshTemplateConcurrencyDatabase();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshTemplateConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('concurrent complete replacements serialize as whole submitted snapshots', function (): void {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for template replacement concurrency evidence.');
    }

    $tenant = TenantKey::factory()->create();
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    givePermissionWithTenant($actor, $tenant->id, 'work_instructions.update');
    $template = WorkInstructionTemplate::factory()->create(['tenant_id' => $tenant->id]);
    WorkInstructionTemplateTranslation::factory()->create([
        'tenant_id' => $tenant->id, 'work_instruction_template_id' => $template->id,
        'locale' => 'de', 'title' => 'Initial', 'body' => 'Initial',
    ]);
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION delay_template_translation_delete() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN PERFORM pg_sleep(0.2); RETURN OLD; END; $$;
        CREATE TRIGGER delay_template_translation_delete BEFORE DELETE
        ON work_instruction_template_translations FOR EACH STATEMENT
        EXECUTE FUNCTION delay_template_translation_delete();
        SQL);
    $snapshots = [
        1 => ['de' => ['title' => 'A German', 'body' => 'A'], 'en' => ['title' => 'A English', 'body' => 'A']],
        2 => ['de' => ['title' => 'B German', 'body' => 'B'], 'en' => ['title' => 'B English', 'body' => 'B']],
    ];
    $directory = sys_get_temp_dir().'/template-replacement-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create template concurrency directory.');
    }
    $signal = $directory.'/start';
    DB::disconnect();
    $pids = [];

    try {
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork template concurrency worker.');
            }
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
                while (! is_file($signal)) {
                    usleep(10_000);
                }
                try {
                    app(WorkInstructionTemplateService::class)->replace(
                        User::query()->findOrFail($actor->id),
                        $template->id,
                        $snapshots[$worker],
                        ContentLocale::German,
                    );
                    $result = 'success';
                } catch (Throwable $exception) {
                    $result = $exception::class;
                }
                file_put_contents($directory."/result-{$worker}", $result);
                exit(0);
            }
            $pids[] = $pid;
        }
        file_put_contents($signal, 'go');
        foreach ($pids as $pid) {
            expect(pcntl_waitpid($pid, $status))->toBe($pid)
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }
        DB::purge();
        DB::reconnect();
        $actual = WorkInstructionTemplateTranslation::query()
            ->where('work_instruction_template_id', $template->id)
            ->orderBy('locale')->get()->mapWithKeys(fn ($translation): array => [
                $translation->locale->value => ['title' => $translation->title, 'body' => $translation->body],
            ])->all();

        expect(trim((string) file_get_contents($directory.'/result-1')))->toBe('success')
            ->and(trim((string) file_get_contents($directory.'/result-2')))->toBe('success')
            ->and(in_array($actual, $snapshots, true))->toBeTrue();
    } finally {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
        DB::purge();
        DB::reconnect();
    }
});
