<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Contracts\TenantKekRotationAuthority;
use App\Exceptions\CorruptedEncryptedAttributeException;
use App\Models\Person;
use App\Models\Tenant;
use App\Models\TenantKey;
use App\Services\RuntimeHeartbeatService;
use App\Services\TenantKekReference;
use App\Services\TenantKekRegistry;
use App\Services\TenantKekRotationPlan;
use App\Services\TenantProvisioner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ManagesGlobalIdentityFixture;

uses(DatabaseTruncation::class, ManagesGlobalIdentityFixture::class)->group('serial');

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/secpal-tenant-kek-'.bin2hex(random_bytes(12));
    mkdir($this->directory, 0700);
    $this->sourcePath = $this->directory.'/old.key';
    $this->targetPath = $this->directory.'/new.key';
    $this->authorityId = (string) Str::uuid();
    $this->source = syntheticTenantKek($this->sourcePath, $this->authorityId, 1);
    $this->target = syntheticTenantKek($this->targetPath, $this->authorityId, 2);
    $this->operation = (string) Str::uuid();
    $this->plan = new TenantKekRotationPlan($this->operation, $this->source, $this->target, $this->sourcePath, $this->targetPath);

    TenantKey::setKekPath($this->sourcePath);
    app()->instance(TenantKekReference::class, $this->source);
    $this->first = app(TenantProvisioner::class)->create();
    $this->second = app(TenantProvisioner::class)->create();
    $this->person = Person::factory()->create([
        'tenant_id' => $this->first->tenant_id,
        'email_plain' => 'synthetic@example.test',
        'note_enc' => 'Synthetic note',
    ]);
});

afterEach(function (): void {
    TenantKey::setKekPath(null);
    (new Filesystem)->deleteDirectory($this->directory);
    DB::statement('TRUNCATE tenants, tenant_keys, tenant_kek_state, tenant_kek_generations RESTART IDENTITY CASCADE');
});

test('clean setup and rewrap preserve independent tenant keys and business ciphertext', function (): void {
    $personEnvelope = $this->person->fresh()->getRawOriginal();
    $firstDek = $this->first->unwrapDek();
    $firstIndex = $this->first->generateBlindIndex('same');
    $secondDek = $this->second->unwrapDek();
    expect($this->first->id)->not->toBe((string) $this->first->tenant_id)
        ->and($this->first->tenant->id)->toBe($this->first->tenant_id)
        ->and(Tenant::query()->count())->toBe(2)
        ->and($firstDek)->not->toBe($secondDek)
        ->and($firstIndex)->not->toBe($this->second->generateBlindIndex('same'));

    $this->artisan('keys:rotate-kek', ['operation' => $this->operation])->assertFailed();
    approveTenantKekPlan($this->plan);
    $this->artisan('keys:rotate-kek', ['operation' => $this->operation])
        ->expectsOutput('Tenant KEK rewrap committed.')
        ->assertSuccessful();
    $this->artisan('keys:rotate-kek', ['operation' => $this->operation])
        ->expectsOutput('Tenant KEK rewrap was already committed.')
        ->assertSuccessful();

    expect(fn () => $this->first->unwrapDek())->toThrow(RuntimeException::class, 'does not match the committed generation');
    app(RuntimeHeartbeatService::class)->recordSchedulerHeartbeat();
    $this->getJson('/health/ready')->assertServiceUnavailable();
    expect(hash_file('sha256', $this->sourcePath))->toBe($this->source->fingerprint)
        ->and(hash_file('sha256', $this->targetPath))->toBe($this->target->fingerprint);

    TenantKey::setKekPath($this->targetPath);
    app()->instance(TenantKekReference::class, $this->target);
    $this->getJson('/health/ready')->assertOk();
    $newKey = $this->first->fresh();
    expect($newKey->dek_nonce)->not->toBe($this->first->dek_nonce)
        ->and($newKey->idx_nonce)->not->toBe($this->first->idx_nonce)
        ->and($newKey->key_version)->toBe($this->first->key_version)
        ->and($newKey->kek_generation)->toBe(2)
        ->and($this->first->unwrapDek())->toBe($firstDek)
        ->and($this->second->unwrapDek())->toBe($secondDek)
        ->and($newKey->generateBlindIndex('same'))->toBe($firstIndex)
        ->and($this->person->fresh()->getRawOriginal())->toBe($personEnvelope)
        ->and($this->person->fresh()->note_enc)->toBe('Synthetic note');
});

test('an interrupted rewrap rolls back prior updates and permits the approved retry', function (): void {
    approveTenantKekPlan($this->plan);
    $original = DB::table('tenant_keys')->orderBy('id')->get()->toArray();
    $updates = 0;
    $interrupt = true;
    TenantKey::updated(static function () use (&$updates, &$interrupt): void {
        if ($interrupt && ++$updates === 1) {
            throw new RuntimeException('Synthetic interruption before commit.');
        }
    });

    expect(fn () => app(TenantKekRegistry::class)->rotate($this->operation))
        ->toThrow(RuntimeException::class, 'Synthetic interruption before commit.');
    expect($updates)->toBe(1)
        ->and(DB::table('tenant_keys')->orderBy('id')->get()->toArray())->toEqual($original)
        ->and(DB::table('tenant_kek_generations')->count())->toBe(1)
        ->and($this->person->fresh()->note_enc)->toBe('Synthetic note');

    $interrupt = false;
    expect(app(TenantKekRegistry::class)->rotate($this->operation))->toBeTrue();
});

test('a lost acknowledgement reconciles the committed transition without another rewrap', function (): void {
    approveTenantKekPlan($this->plan);
    $interrupt = true;
    TenantKey::updated(static function () use (&$interrupt): void {
        if ($interrupt) {
            $interrupt = false;
            DB::afterCommit(static function (): never {
                throw new RuntimeException('Synthetic interruption after commit.');
            });
        }
    });

    $this->artisan('keys:rotate-kek', ['operation' => $this->operation])
        ->expectsOutputToContain('was not confirmed')
        ->assertFailed();
    $committed = $this->first->fresh()->dek_wrapped;
    expect(DB::table('tenant_kek_state')->value('generation'))->toBe(2)
        ->and(app(TenantKekRegistry::class)->rotate($this->operation))->toBeFalse()
        ->and($this->first->fresh()->dek_wrapped)->toBe($committed);
});

test('losing the maintenance process before commit leaves the old generation usable', function (): void {
    $original = DB::table('tenant_keys')->orderBy('id')->get()->toArray();
    $worker = tenantKekWorker('interrupt', $this->first->tenant_id, $this->source, $this->sourcePath, $this->plan);
    try {
        $worker->start();
        expect($worker->waitUntil(static fn (string $type, string $buffer): bool => $type === Process::OUT && str_contains($buffer, 'ROW_WRITTEN')))
            ->toBeTrue();
        expect(DB::table('tenant_kek_state')->value('generation'))->toBe(1)
            ->and(DB::table('tenant_keys')->orderBy('id')->get()->toArray())->toEqual($original);
    } finally {
        $worker->stop(0, 9);
    }

    expect($this->person->fresh()->note_enc)->toBe('Synthetic note')
        ->and(DB::table('tenant_kek_generations')->count())->toBe(1)
        ->and(DB::table('tenant_keys')->orderBy('id')->get()->toArray())->toEqual($original);
    approveTenantKekPlan($this->plan);
    expect(app(TenantKekRegistry::class)->rotate($this->operation))->toBeTrue();
});

test('wrong key files and tenant or purpose substitutions fail closed', function (): void {
    TenantKey::setKekPath($this->targetPath);
    expect(fn () => $this->first->unwrapDek())->toThrow(RuntimeException::class, 'Exact Tenant KEK generation is unavailable');
    TenantKey::setKekPath($this->directory.'/absent.key');
    expect(fn () => $this->first->unwrapDek())->toThrow(RuntimeException::class, 'Exact Tenant KEK generation is unavailable');
    TenantKey::setKekPath($this->sourcePath);

    $this->first->forceFill(['dek_nonce' => $this->second->dek_nonce, 'dek_wrapped' => $this->second->dek_wrapped]);
    expect(fn () => $this->first->unwrapDek())->toThrow(CorruptedEncryptedAttributeException::class);
    $this->first->forceFill(['dek_nonce' => $this->first->idx_nonce, 'dek_wrapped' => $this->first->idx_wrapped]);
    expect(fn () => $this->first->unwrapDek())->toThrow(CorruptedEncryptedAttributeException::class);
    $this->first->forceFill(['kek_generation' => 2]);
    expect(fn () => $this->first->unwrapDek())->toThrow(CorruptedEncryptedAttributeException::class);

    approveTenantKekPlan(new TenantKekRotationPlan($this->operation, $this->source, $this->target, $this->targetPath, $this->sourcePath));
    expect(fn () => app(TenantKekRegistry::class)->rotate($this->operation))->toThrow(RuntimeException::class, 'Exact Tenant KEK generation is unavailable')
        ->and(DB::table('tenant_kek_state')->value('generation'))->toBe(1);
});

test('committed generation history rejects republishing old material and supports exact snapshot recovery', function (): void {
    $snapshotKey = $this->first->getRawOriginal();
    $snapshotState = (array) DB::table('tenant_kek_state')->first();
    approveTenantKekPlan($this->plan);
    expect(app(TenantKekRegistry::class)->rotate($this->operation))->toBeTrue();

    $reused = new TenantKekReference($this->authorityId, 3, $this->source->fingerprint);
    $secondOperation = (string) Str::uuid();
    approveTenantKekPlan(new TenantKekRotationPlan($secondOperation, $this->target, $reused, $this->targetPath, $this->sourcePath));
    expect(fn () => app(TenantKekRegistry::class)->rotate($secondOperation))->toThrow(RuntimeException::class, 'already been used')
        ->and(DB::table('tenant_kek_generations')->orderBy('generation')->pluck('generation')->all())->toBe([1, 2]);

    // Restore only the pre-rotation synthetic snapshot and its exact external key.
    DB::transaction(static function () use ($snapshotKey, $snapshotState): void {
        DB::table('tenant_keys')->delete();
        DB::table('tenant_kek_state')->delete();
        DB::table('tenant_kek_generations')->where('generation', '>', 1)->delete();
        DB::table('tenant_kek_state')->insert($snapshotState);
        DB::table('tenant_keys')->insert($snapshotKey);
    });
    expect($this->person->fresh()->note_enc)->toBe('Synthetic note');
    TenantKey::setKekPath($this->targetPath);
    expect(fn () => $this->person->fresh()->note_enc)->toThrow(RuntimeException::class, 'Exact Tenant KEK generation is unavailable');
});

test('independent read and provisioning sessions wait for rewrap and use the committed generation', function (): void {
    approveTenantKekPlan($this->plan);
    $workers = [
        tenantKekWorker('read', $this->first->tenant_id, $this->source, $this->sourcePath),
        tenantKekWorker('create', $this->first->tenant_id, $this->target, $this->targetPath),
    ];
    $observe = true;
    TenantKey::updated(static function () use ($workers, &$observe): void {
        if ($observe) {
            $observe = false;
            foreach ($workers as $worker) {
                assertTenantKekWorkerBlocked($worker);
            }
        }
    });

    try {
        expect(app(TenantKekRegistry::class)->rotate($this->operation))->toBeTrue()
            ->and($workers[0]->wait())->toBe(0)
            ->and($workers[1]->wait())->toBe(0)
            ->and(Tenant::query()->count())->toBe(3)
            ->and(TenantKey::query()->distinct()->pluck('kek_generation')->all())->toBe([2]);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
    }
});

function approveTenantKekPlan(TenantKekRotationPlan $plan): void
{
    app()->instance(TenantKekRotationAuthority::class, new class($plan) implements TenantKekRotationAuthority
    {
        public function __construct(private TenantKekRotationPlan $plan) {}

        public function approvedPlan(string $operationId): TenantKekRotationPlan
        {
            return $this->plan;
        }
    });
}

function syntheticTenantKek(string $path, string $id, int $generation): TenantKekReference
{
    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    try {
        file_put_contents($path, $key);
        chmod($path, 0400);

        return new TenantKekReference($id, $generation, hash('sha256', $key));
    } finally {
        sodium_memzero($key);
    }
}

function tenantKekWorker(
    string $operation,
    int $tenant,
    TenantKekReference $reference,
    string $path,
    ?TenantKekRotationPlan $maintenance = null,
): Process {
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        $db->statement("SET statement_timeout = '12s'");
        echo $db->scalar('SELECT pg_backend_pid()').PHP_EOL;

        if ($argv[1] === 'interrupt') {
            $source = $app->make(App\Services\TenantKekReference::class);
            $target = new App\Services\TenantKekReference($source->id, 2, $argv[4]);
            $plan = new App\Services\TenantKekRotationPlan($argv[3], $source, $target, getenv('KEK_PATH'), $argv[5]);
            $app->instance(App\Contracts\TenantKekRotationAuthority::class, new class($plan) implements App\Contracts\TenantKekRotationAuthority {
                public function __construct(private App\Services\TenantKekRotationPlan $plan) {}
                public function approvedPlan(string $operationId): App\Services\TenantKekRotationPlan
                {
                    throw_unless($this->plan->operationId === $operationId);
                    return $this->plan;
                }
            });
            App\Models\TenantKey::updated(static function (): never {
                echo 'ROW_WRITTEN'.PHP_EOL;
                sleep(15);
                throw new RuntimeException('The qualification harness did not interrupt the process.');
            });
            $app->make(App\Services\TenantKekRegistry::class)->rotate($argv[3]);
            exit(1);
        }

        if ($argv[1] === 'read') {
            try {
                App\Models\TenantKey::forTenant((int) $argv[2])->unwrapDek();
            } catch (RuntimeException $e) {
                exit($e->getMessage() === 'Configured Tenant KEK does not match the committed generation.' ? 0 : 1);
            }
            exit(1);
        }
        exit($app->make(App\Services\TenantProvisioner::class)->create()->kek_generation === 2 ? 0 : 1);
        PHP;

    $arguments = [PHP_BINARY, '-r', $code, $operation, (string) $tenant];
    if ($maintenance !== null) {
        array_push($arguments, $maintenance->operationId, $maintenance->to->fingerprint, $maintenance->targetPath);
    }

    return new Process($arguments, base_path(), [
        'APP_ENV' => 'testing',
        'APP_KEY' => config()->string('app.key'),
        'DB_CONNECTION' => 'pgsql',
        'SECPAL_TEST_DATABASE' => config()->string('database.connections.pgsql.database'),
        'SECPAL_TEST_SCHEMA' => config()->string('database.connections.pgsql.search_path'),
        'KEK_PATH' => $path,
        'TENANT_KEK_ID' => $reference->id,
        'TENANT_KEK_GENERATION' => (string) $reference->generation,
        'TENANT_KEK_FINGERPRINT' => $reference->fingerprint,
    ], timeout: 18);
}

function assertTenantKekWorkerBlocked(Process $worker): void
{
    $worker->start();
    expect($worker->waitUntil(static fn (string $type, string $buffer): bool => $type === Process::OUT && ctype_digit(trim($buffer))))->toBeTrue();
    $pid = (int) trim($worker->getOutput());
    $deadline = microtime(true) + 4;
    do {
        $blocked = DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = ? AND locktype = 'advisory' AND NOT granted)", [$pid]);
        if (! $blocked) {
            usleep(10_000);
        }
    } while (! $blocked && microtime(true) < $deadline);
    expect($blocked)->toBeTrue();
}
