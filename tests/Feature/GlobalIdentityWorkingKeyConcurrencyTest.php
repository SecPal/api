<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Contracts\GlobalIdentityRotationAuthority;
use App\Exceptions\GlobalIdentityKeyException;
use App\Models\GlobalIdentityOperation;
use App\Models\User;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalEmailSchema;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityOperationService;
use App\Services\GlobalIdentity\GlobalIdentityOperationType;
use App\Services\GlobalIdentity\GlobalIdentityRotationCoordinator;
use App\Services\GlobalIdentity\GlobalIdentityRotationKind;
use App\Services\GlobalIdentity\GlobalIdentityRotationPlan;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose as Purpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use App\Services\GlobalIdentity\IdentityLifecycle;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class, Tests\Support\ManagesGlobalIdentityFixture::class)->group('serial');

it('serializes competing bootstraps and delays activation until the active writer commits', function (): void {
    $directory = sys_get_temp_dir().'/secpal-key-concurrency-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    (new FileGlobalIdentityRootProvider($directory))->provision(new GlobalIdentityKeyReference('root', 1));
    config(['global_identity.root_directory' => $directory]);

    $initializers = [registryWorker($directory), registryWorker($directory)];
    $activators = [];
    try {
        foreach ($initializers as $worker) {
            $worker->start();
        }
        foreach ($initializers as $worker) {
            expect($worker->wait())->toBe(0);
        }

        $rows = DB::table('global_identity_working_keys');
        expect((clone $rows)->count())->toBe(count(Purpose::cases()));
        $registry = app(GlobalIdentityWorkingKeyRegistry::class);
        $purpose = Purpose::EmailEncryption;
        $candidate = $registry->stage($purpose);
        $activators = [registryWorker($directory, $candidate), registryWorker($directory, $candidate)];

        $registry->withActiveKey($purpose, function (
            #[SensitiveParameter] string $key,
            GlobalIdentityWorkingKeyContext $active,
        ) use ($activators): void {
            expect($active->reference->version)->toBe(1);
            foreach ($activators as $worker) {
                $worker->start();
            }
            foreach ($activators as $worker) {
                expectRegistryWorkerToWait($worker);
            }
        });

        foreach ($activators as $worker) {
            expect($worker->wait())->toBe(0);
        }
        expect($rows->where('purpose', $purpose->value)->orderBy('key_version')->pluck('state')->all())
            ->toBe(['decrypt-only', 'write-active']);
    } finally {
        foreach ([...$initializers, ...$activators] as $worker) {
            $worker->stop();
        }
        (new Filesystem)->deleteDirectory($directory);
        DB::table('global_identity_working_keys')->delete();
    }
});

it('holds the delivery fence through exact key resolution and use', function (bool $byPurposeVersion): void {
    $directory = sys_get_temp_dir().'/secpal-key-concurrency-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $root = new GlobalIdentityKeyReference('root', 1);
    (new FileGlobalIdentityRootProvider($directory))->provision($root);
    config(['global_identity.root_directory' => $directory]);
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $registry->initialize($root);
    $purpose = Purpose::OperationDeliverySecret;
    $context = new GlobalIdentityWorkingKeyContext($purpose, $registry->activeReference($purpose));
    $worker = registryWorker($directory, $context, blockDelivery: true);
    $use = fn (Closure $callback): mixed => $byPurposeVersion
        ? $registry->withPurposeVersion($purpose, $context->reference->version, $callback)
        : $registry->withKey($context, $callback);

    try {
        $use(function () use ($worker): void {
            $worker->start();
            expectRegistryWorkerToWait($worker);
        });
        expect($worker->wait())->toBe(0)
            ->and(fn () => $use(static fn (): bool => true))->toThrow(GlobalIdentityKeyException::class);
    } finally {
        $worker->stop();
        (new Filesystem)->deleteDirectory($directory);
        DB::table('global_identity_working_keys')->delete();
    }
})->with([false, true]);

it('serializes password reset and incident replacement across committed database sessions', function (bool $replacementFirst): void {
    Queue::fake();
    $directory = sys_get_temp_dir().'/secpal-incident-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $root = new GlobalIdentityKeyReference('root', 1);
    (new FileGlobalIdentityRootProvider($directory))->provision($root);
    config(['global_identity.root_directory' => $directory]);
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $registry->initialize($root);
    $worker = null;
    try {
        $user = User::factory()->create();
        $lifecycle = app(IdentityLifecycle::class);
        $operations = app(GlobalIdentityOperationService::class);
        $lifecycle->request($user, GlobalIdentityOperationType::PendingEmailChange, 'Concurrent+Incident@secpal.dev');
        $old = GlobalIdentityOperation::query()->sole();
        $from = $registry->activeReference(Purpose::OperationDeliverySecret);
        $id = (string) Str::uuid();
        $plan = new GlobalIdentityRotationPlan($id, GlobalIdentityRotationKind::DeliveryCompromise, $from,
            new GlobalIdentityKeyReference($from->id, $from->version + 1), Purpose::OperationDeliverySecret, [$old->id]);
        $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
        $authority->shouldReceive('approvedPlan')->with($id, Mockery::type('string'))->andReturn($plan);
        app()->instance(GlobalIdentityRotationAuthority::class, $authority);
        $rotation = app(GlobalIdentityRotationCoordinator::class);
        $rotation->execute($id, 'start', 10);
        $rotation->execute($id, 'advance', 10);
        $rotation->execute($id, 'advance', 10);
        $resetType = GlobalIdentityOperationType::PasswordReset;
        $lifecycle->request($user, $resetType);
        $reset = GlobalIdentityOperation::query()->where('operation_type', $resetType)->sole();
        $proof = $operations->openToken($reset);
        $worker = identityIncidentWorker($directory, $replacementFirst
            ? ['reset' => $reset->id, 'proof' => $proof]
            : ['incident' => $id, 'source' => $from->id, 'version' => $from->version, 'predecessor' => $old->id]);
        if ($replacementFirst) {
            DB::transaction(function () use ($worker, $rotation, $id): void {
                $rotation->execute($id, 'advance', 10);
                $worker->start();
                expectRegistryWorkerToWait($worker);
            });
        } else {
            $operations->forAccount($user->id, function () use ($worker, $old, $lifecycle, $resetType, $reset, $proof): void {
                $worker->start();
                expectRegistryWorkerToWait($worker);
                expect(DB::table('global_identity_rotation_reissues')->where('operation_id', $old->id)
                    ->lock('FOR UPDATE NOWAIT')->first())->not->toBeNull();
                expect($lifecycle->consume($reset->id, $resetType, $proof, 'Synthetic-incident-reset-835!'))->toBeTrue();
            });
        }
        expect($worker->wait())->toBe(0);
        $rotation->execute($id, 'advance', 10);
        $rotation->execute($id, 'complete', 10);
        $replacements = GlobalIdentityOperation::query()->where('replaces_operation_id', $old->id)->get();
        expect($replacements)->toHaveCount($replacementFirst ? 1 : 0)
            ->and(DB::table('global_identity_rotation_reissues')->count())->toBe(0)
            ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->count())->toBe(0)
            ->and($reset->refresh()->terminal_reason)->toBe('consumed');
        if ($replacementFirst) {
            $replacement = $replacements->sole();
            expect($replacement->terminal_reason)->toBe('revoked')
                ->and($operations->claim($replacement->id, 'fixture'))->toBeNull();
        }
    } finally {
        $worker?->stop();
        $this->truncateTablesForAllConnections();
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([false, true]);

/** @param array<string, int|string> $request */
function identityIncidentWorker(string $directory, array $request): Process
{
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Queue::fake();
        $db = Illuminate\Support\Facades\DB::connection();
        $db->statement("SET statement_timeout = '10s'");
        echo $db->scalar('SELECT pg_backend_pid()').PHP_EOL;
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        if (isset($input['reset'])) {
            exit($app->make(App\Services\GlobalIdentity\IdentityLifecycle::class)->consume(
                $input['reset'], App\Services\GlobalIdentity\GlobalIdentityOperationType::PasswordReset,
                $input['proof'], 'Synthetic-incident-reset-835!',
            ) ? 0 : 1);
        }
        $app->instance(App\Contracts\GlobalIdentityRotationAuthority::class, new class($input) implements App\Contracts\GlobalIdentityRotationAuthority {
            public function __construct(private array $input) {}
            public function approvedPlan(string $operationId, string $action): App\Services\GlobalIdentity\GlobalIdentityRotationPlan
            {
                return new App\Services\GlobalIdentity\GlobalIdentityRotationPlan(
                    $operationId, App\Services\GlobalIdentity\GlobalIdentityRotationKind::DeliveryCompromise,
                    new App\Services\GlobalIdentity\GlobalIdentityKeyReference($this->input['source'], $this->input['version']),
                    new App\Services\GlobalIdentity\GlobalIdentityKeyReference($this->input['source'], $this->input['version'] + 1),
                    App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret, [$this->input['predecessor']],
                );
            }
        });
        $app->make(App\Services\GlobalIdentity\GlobalIdentityRotationCoordinator::class)->execute($input['incident'], 'advance', 10);
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $code], base_path(),
        [
            'APP_ENV' => 'testing',
            'APP_KEY' => config()->string('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'GLOBAL_IDENTITY_ROOT_DIRECTORY' => $directory,
            'SECPAL_TEST_DATABASE' => config()->string('database.connections.pgsql.database'),
            'SECPAL_TEST_SCHEMA' => (string) DB::scalar('SELECT current_schema()'),
        ],
        input: json_encode($request, JSON_THROW_ON_ERROR), timeout: 15,
    );
}

function expectRegistryWorkerToWait(Process $worker): void
{
    $worker->waitUntil(static fn (string $type, string $output): bool => preg_match('/^[0-9]+\n$/', $output) === 1);
    $pid = (int) trim($worker->getOutput());
    expect($pid)->toBeGreaterThan(0);
    $deadline = microtime(true) + 5;
    do {
        $blocked = (bool) DB::scalar('SELECT cardinality(pg_blocking_pids(?)) > 0', [$pid]);
        if (! $blocked) {
            usleep(10_000);
        }
    } while (! $blocked && $worker->isRunning() && microtime(true) < $deadline);
    expect($blocked)->toBeTrue();
}

function registryWorker(string $directory, ?GlobalIdentityWorkingKeyContext $candidate = null, bool $blockDelivery = false): Process
{
    $script = <<<'WORKER'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    $db->statement("SET statement_timeout = '10s'");
    echo $db->scalar('SELECT pg_backend_pid()').PHP_EOL;
    $registry = $app->make(App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry::class);
    if ($argv[1] === '') {
        $registry->initialize(new App\Services\GlobalIdentity\GlobalIdentityKeyReference('root', 1));
    } elseif ($argv[3] === 'block') {
        $registry->blockDeliveryVersion(
            new App\Services\GlobalIdentity\GlobalIdentityKeyReference($argv[1], (int) $argv[2]),
            App\Services\GlobalIdentity\GlobalIdentityTerminalReason::SecurityRevoked,
        );
    } else {
        $registry->activate(new App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext(
            App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose::EmailEncryption,
            new App\Services\GlobalIdentity\GlobalIdentityKeyReference($argv[1], (int) $argv[2]),
        ));
    }
    WORKER;

    return new Process(
        [PHP_BINARY, '-r', $script, $candidate?->reference->id ?? '', (string) $candidate?->reference->version, $blockDelivery ? 'block' : 'activate'],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'APP_KEY' => config()->string('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'GLOBAL_IDENTITY_ROOT_DIRECTORY' => $directory,
            'SECPAL_TEST_DATABASE' => config()->string('database.connections.pgsql.database'),
            'SECPAL_TEST_SCHEMA' => (string) DB::scalar('SELECT current_schema()'),
        ],
        timeout: 15,
    );
}
