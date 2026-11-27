<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\GlobalIdentityKeyException;
use App\Jobs\DeliverGlobalIdentityOperation;
use App\Models\GlobalIdentityOperation;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryOutcome as Outcome;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryRenderer;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryResult;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryState as State;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryTransport;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryWorker;
use App\Services\GlobalIdentity\GlobalIdentityJournalAuthenticator;
use App\Services\GlobalIdentity\GlobalIdentityJournalInterval;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityOperationContext;
use App\Services\GlobalIdentity\GlobalIdentityOperationRestore;
use App\Services\GlobalIdentity\GlobalIdentityOperationService;
use App\Services\GlobalIdentity\GlobalIdentityOperationSubject;
use App\Services\GlobalIdentity\GlobalIdentityOperationType as Operation;
use App\Services\GlobalIdentity\GlobalIdentityTerminalJournalEntry;
use App\Services\GlobalIdentity\GlobalIdentityTerminalReason;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class)->group('serial');

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/secpal-delivery-'.bin2hex(random_bytes(12));
    mkdir($this->directory, 0700);
    $root = new GlobalIdentityKeyReference('root', 1);
    (new FileGlobalIdentityRootProvider($this->directory))->provision($root);
    config(['global_identity.root_directory' => $this->directory]);
    app(GlobalIdentityWorkingKeyRegistry::class)->initialize($root);
    $this->subject = new SyntheticOperationSubject;
    $this->operations = new GlobalIdentityOperationService(
        app(GlobalIdentityWorkingKeyRegistry::class),
        app(App\Services\GlobalIdentity\GlobalIdentityOperationCodec::class),
        $this->subject,
    );
    $this->transport = new SyntheticIdentityTransport;
    $this->renderer = new SyntheticIdentityRenderer;
    $this->worker = new GlobalIdentityDeliveryWorker($this->operations, $this->subject, $this->renderer, $this->transport);
    $this->context = new GlobalIdentityOperationContext(Operation::PasswordReset, (string) Str::uuid(), (string) Str::uuid(), 1);
});

afterEach(function (): void {
    DB::table('global_identity_operations')->delete();
    DB::table('global_identity_working_keys')->delete();
    (new Filesystem)->deleteDirectory($this->directory);
});

it('delivers a committed attempt from an operation ID and consumes only by the verifier', function (): void {
    Log::spy();
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $this->transport->inspect = function (): void {
        $operation = GlobalIdentityOperation::query()->findOrFail($this->context->id);
        expect($operation->delivery_state)->toBe(State::DispatchCommitted);
        expect(DB::connection()->scalar('SELECT octet_length(transport_idempotency_key) FROM global_identity_operations WHERE id = ?', [$this->context->id]))->toBe(32);
        $snapshot = DB::scalar('SELECT to_jsonb(o)::text FROM global_identity_operations o WHERE o.id = ?', [$this->context->id]);
        expect($snapshot)->not->toContain('source@secpal.app', 'https://', $this->renderer->token);
    };
    $job = new DeliverGlobalIdentityOperation($this->context->id);
    expect(serialize($job))->toContain($this->context->id)->not->toContain('source@secpal.app', 'https://');
    $job->handle($this->worker);

    $row = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($row->delivery_state)->toBe(State::Delivered)
        ->and($row->delivery_token_enc)->toBeNull()
        ->and($row->token_hash)->toHaveLength(64)
        ->and($row->toJson())->not->toContain('token_hash', 'delivery_token_enc', $this->renderer->token)
        ->and($this->transport->recipient)->toBe('source@secpal.app')
        ->and($this->transport->sentKeys)->toHaveCount(1)
        ->and($this->transport->sentKeys[0])->toHaveLength(43);
    expect($this->operations->consume($this->context->id, $this->renderer->token))->toBeTrue()
        ->and($this->operations->consume($this->context->id, $this->renderer->token))->toBeFalse();
    Log::shouldHaveReceived('info')->with('Global identity operation.', Mockery::on(static fn (array $data): bool => array_keys($data) === ['operation_id', 'state', 'event']))->times(5);
});

it('stops the transport when the dispatch write is rejected', function (): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    GlobalIdentityOperation::saving(static fn (GlobalIdentityOperation $operation): bool => $operation->delivery_state !== State::DispatchCommitted);

    try {
        expect(fn () => $this->worker->deliver($this->context->id))->toThrow(GlobalIdentityKeyException::class);
    } finally {
        GlobalIdentityOperation::flushEventListeners();
    }
    expect($this->transport->sentKeys)->toBe([])
        ->and(GlobalIdentityOperation::query()->findOrFail($this->context->id)->delivery_state)->toBe(State::Claimed);
});

it('retains the protected token for controlled retry when the exact custody key is unavailable', function (): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $rootPath = $this->directory.'/root.1.key';
    rename($rootPath, $rootPath.'.held');
    try {
        $this->worker->deliver($this->context->id);
        $row = GlobalIdentityOperation::query()->findOrFail($this->context->id);
        expect($row->delivery_state)->toBe(State::Retryable)
            ->and($row->delivery_token_enc)->not->toBeNull()
            ->and($this->transport->sentKeys)->toBe([]);
    } finally {
        rename($rootPath.'.held', $rootPath);
    }
    $this->worker->deliver($this->context->id);
    expect(GlobalIdentityOperation::query()->findOrFail($this->context->id)->delivery_state)->toBe(State::Delivered);
});

it('rejects revocation or a changed email version between preparation and commitment', function (): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $this->renderer->beforeReturn = fn () => $this->operations->invalidate($this->context->id, GlobalIdentityTerminalReason::Revoked);
    $this->worker->deliver($this->context->id);
    $state = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($state->delivery_state)->toBe(State::Terminal)
        ->and($state->token_hash)->toBeNull()
        ->and($state->delivery_token_enc)->toBeNull()
        ->and($state->dispatch_committed_at)->toBeNull()
        ->and($this->transport->sentKeys)->toBe([]);

    $changed = new GlobalIdentityOperationContext(Operation::EmailVerification, (string) Str::uuid(), $this->context->userId, 1);
    $this->operations->create($changed, now()->toImmutable()->addMinutes(10));
    $this->renderer->beforeReturn = function (): void {
        $this->subject->current = false;
    };
    $this->worker->deliver($changed->id);
    expect(GlobalIdentityOperation::query()->findOrFail($changed->id)->delivery_state)->toBe(State::Terminal)
        ->and($this->transport->sentKeys)->toBe([]);
});

it('rejects an expired or consumed token between preparation and commitment', function (bool $expire): void {
    $this->freezeTime();
    $this->operations->create($this->context, now()->toImmutable()->addSeconds(30));
    $this->renderer->beforeReturn = function () use ($expire): void {
        if ($expire) {
            $this->travel(30)->seconds();
        } else {
            expect($this->operations->consume($this->context->id, $this->renderer->token))->toBeTrue();
        }
    };
    $this->worker->deliver($this->context->id);
    $row = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($row->delivery_state)->toBe(State::Terminal)
        ->and($row->terminal_reason)->toBe($expire ? 'expired' : 'consumed')
        ->and($row->dispatch_committed_at)->toBeNull()
        ->and($row->delivery_token_enc)->toBeNull()
        ->and($this->transport->sentKeys)->toBe([]);
    $this->travelBack();
})->with(['expiry' => true, 'consumption' => false]);

it('keeps an ambiguous attempt unknown until an authoritative replay is permitted', function (): void {
    Log::spy();
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $this->transport->sendOutcome = Outcome::Unknown;
    $this->worker->deliver($this->context->id);
    expect(GlobalIdentityOperation::query()->findOrFail($this->context->id)->delivery_state)->toBe(State::Unknown);
    $this->worker->deliver($this->context->id);
    $this->worker->reconcile($this->context->id);
    expect($this->transport->sentKeys)->toHaveCount(1);

    $this->transport->lookupOutcome = Outcome::Absent;
    $this->transport->sendOutcome = Outcome::Accepted;
    $this->worker->reconcile($this->context->id);
    expect($this->transport->sentKeys)->toHaveCount(2)
        ->and($this->transport->sentKeys[1])->toBe($this->transport->sentKeys[0])
        ->and(GlobalIdentityOperation::query()->findOrFail($this->context->id)->delivery_state)->toBe(State::Delivered);
});

it('prefers provider-reference reconciliation and preserves ambiguity for unsupported transports', function (): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $this->transport->sendOutcome = Outcome::Unknown;
    $this->transport->reference = 'opaque-provider-key';
    $this->worker->deliver($this->context->id);
    $this->transport->referenceOutcome = Outcome::Accepted;
    $this->worker->reconcile($this->context->id);
    expect($this->transport->lookups)->toBe(['reference'])
        ->and($this->transport->sentKeys)->toHaveCount(1)
        ->and(GlobalIdentityOperation::query()->findOrFail($this->context->id)->delivery_state)->toBe(State::Delivered);

    $unsupported = new GlobalIdentityOperationContext(Operation::EmailVerification, (string) Str::uuid(), $this->context->userId, 1);
    $this->operations->create($unsupported, now()->toImmutable()->addMinutes(10));
    $this->transport->keyAccepted = false;
    $this->transport->reference = null;
    $this->worker->deliver($unsupported->id);
    $this->worker->reconcile($unsupported->id);
    expect($this->transport->lookups)->toBe(['reference'])
        ->and($this->transport->sentKeys[1])->toBeNull()
        ->and(GlobalIdentityOperation::query()->findOrFail($unsupported->id)->delivery_state)->toBe(State::Unknown);
});

it('never infers absence from another provider or from a key omitted on the first dispatch', function (bool $sameProvider, bool $initialKey): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $this->transport->sendOutcome = Outcome::Unknown;
    $this->transport->keyAccepted = $initialKey;
    $this->worker->deliver($this->context->id);

    $this->transport->providerIdentity = $sameProvider ? 'fixture' : 'another-fixture';
    $this->transport->keyAccepted = true;
    $this->transport->lookupOutcome = Outcome::Absent;
    $this->transport->sendOutcome = Outcome::Accepted;
    $this->worker->reconcile($this->context->id);

    expect($this->transport->lookups)->toBe([])
        ->and($this->transport->sentKeys)->toHaveCount(1)
        ->and(GlobalIdentityOperation::query()->findOrFail($this->context->id)->delivery_state)->toBe(State::Unknown);
})->with([
    'changed provider' => [false, true],
    'key added after dispatch' => [true, false],
]);

it('invalidates an old lease and preserves a terminal decision against a late transport response', function (): void {
    Log::spy();
    $this->freezeTime();
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $old = $this->operations->claim($this->context->id, 'fixture');
    expect($old)->not->toBeNull();
    $previousKey = $this->operations->idempotencyKey($old);
    $this->travel(61)->seconds();
    $new = $this->operations->claim($this->context->id, 'fixture');
    expect($new)->not->toBeNull()
        ->and($new->delivery_attempt_id)->not->toBe($old->delivery_attempt_id)
        ->and($new->delivery_fence_version)->toBeGreaterThan($old->delivery_fence_version)
        ->and($this->operations->idempotencyKey($new))->not->toBe($previousKey)
        ->and($this->operations->commitDispatch($old))->toBeFalse()
        ->and($this->operations->commitDispatch($new))->toBeTrue();
    $token = $this->operations->openToken($new);
    $this->operations->invalidate($this->context->id, GlobalIdentityTerminalReason::Revoked);
    $this->operations->recordResult($new, new GlobalIdentityDeliveryResult(Outcome::Accepted, 'synthetic-reference'));
    $row = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($row->delivery_state)->toBe(State::Terminal)
        ->and($row->delivery_token_enc)->toBeNull()
        ->and($this->operations->consume($this->context->id, $token))->toBeFalse();
    Log::shouldHaveReceived('info')->with('Global identity operation.', [
        'operation_id' => $this->context->id,
        'state' => State::Terminal->value,
        'event' => 'late_transport_accepted',
    ])->once();
    $this->travelBack();
});

it('invalidates the fence at the lease boundary before any transport call', function (): void {
    $this->freezeTime();
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $claim = $this->operations->claim($this->context->id, 'fixture');
    expect($claim)->not->toBeNull();
    $this->travel(60)->seconds();
    expect($this->operations->commitDispatch($claim))->toBeFalse();
    $row = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($row->delivery_state)->toBe(State::Retryable)
        ->and($row->delivery_fence_version)->toBeGreaterThan($claim->delivery_fence_version)
        ->and($row->dispatch_committed_at)->toBeNull()
        ->and($this->transport->sentKeys)->toBe([]);
    $this->travelBack();
});

it('reconciles a claim with an expired lease and no dispatch commitment', function (): void {
    $this->freezeTime();
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $claim = $this->operations->claim($this->context->id, 'fixture');
    expect($claim)->not->toBeNull();
    $this->travel(61)->seconds();

    $this->worker->reconcile($this->context->id);
    $row = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($row->delivery_state)->toBe(State::Retryable)
        ->and($row->delivery_fence_version)->toBeGreaterThan($claim->delivery_fence_version)
        ->and($row->delivery_token_enc)->not->toBeNull()
        ->and($this->transport->lookups)->toBe([])
        ->and($this->transport->sentKeys)->toBe([]);
    $this->travelBack();
});

it('keeps a committed attempt with a lost result unknown without authoritative status', function (): void {
    $this->freezeTime();
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $claim = $this->operations->claim($this->context->id, 'fixture');
    expect($claim)->not->toBeNull()->and($this->operations->commitDispatch($claim))->toBeTrue();
    $this->travel(61)->seconds();

    $this->worker->reconcile($this->context->id);
    $this->worker->deliver($this->context->id);
    $row = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($row->delivery_state)->toBe(State::Unknown)
        ->and($row->delivery_attempt_id)->toBe($claim->delivery_attempt_id)
        ->and($row->delivery_fence_version)->toBeGreaterThan($claim->delivery_fence_version)
        ->and($row->delivery_token_enc)->not->toBeNull()
        ->and($this->transport->lookups)->toBe([])
        ->and($this->transport->sentKeys)->toBe([]);
    $this->travelBack();
});

it('cleans residual protected material on a terminal row without consulting the provider', function (): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $envelope = DB::table('global_identity_operations')->where('id', $this->context->id)->value('delivery_token_enc');
    $this->operations->invalidate($this->context->id, GlobalIdentityTerminalReason::Cancelled);
    DB::table('global_identity_operations')->where('id', $this->context->id)->update(['delivery_token_enc' => $envelope]);

    $this->worker->reconcile($this->context->id);
    $row = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($row->delivery_state)->toBe(State::Terminal)
        ->and($row->delivery_token_enc)->toBeNull()
        ->and($row->getAttribute('terminal_reason'))->toBe('cancelled')
        ->and($this->transport->lookups)->toBe([])
        ->and($this->transport->sentKeys)->toBe([]);
});

it('admits one claim across separate PostgreSQL sessions and keeps its attempt binding immutable', function (): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        $db->statement("SET statement_timeout = '8s'");
        echo $db->scalar('SELECT pg_backend_pid()').PHP_EOL;
        $subject = new class implements App\Services\GlobalIdentity\GlobalIdentityOperationSubject {
            public function isCurrent(App\Services\GlobalIdentity\GlobalIdentityOperationContext $operation): bool { return true; }
            public function recipient(App\Services\GlobalIdentity\GlobalIdentityOperationContext $operation): string { throw new LogicException; }
        };
        $app->instance(App\Services\GlobalIdentity\GlobalIdentityOperationSubject::class, $subject);
        $service = $app->make(App\Services\GlobalIdentity\GlobalIdentityOperationService::class);
        exit($service->claim($argv[1], 'fixture') === null ? 0 : 1);
        PHP;
    $worker = new Process(
        [PHP_BINARY, '-r', $code, $this->context->id],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'APP_KEY' => config()->string('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'GLOBAL_IDENTITY_ROOT_DIRECTORY' => $this->directory,
            'SECPAL_TEST_DATABASE' => config()->string('database.connections.pgsql.database'),
            'SECPAL_TEST_SCHEMA' => (string) DB::scalar('SELECT current_schema()'),
        ],
        timeout: 15,
    );
    try {
        DB::transaction(function () use ($worker): void {
            expect($this->operations->claim($this->context->id, 'fixture'))->not->toBeNull();
            $worker->start();
            expect($worker->waitUntil(static fn (string $type, string $text): bool => $type === Process::OUT && preg_match('/^\d+\n$/', $text) === 1))->toBeTrue();
            $pid = (int) trim($worker->getOutput());
            $until = microtime(true) + 4;
            do {
                $blocked = (bool) DB::scalar('SELECT cardinality(pg_blocking_pids(?)) > 0', [$pid]);
                if (! $blocked) {
                    usleep(10_000);
                }
            } while (! $blocked && microtime(true) < $until);
            expect($blocked)->toBeTrue();
        });
        expect($worker->wait())->toBe(0);
    } finally {
        $worker->stop();
    }
    $operation = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    expect($operation->delivery_attempt_count)->toBe(1);
    expect(fn () => DB::table('global_identity_operations')->where('id', $this->context->id)->update([
        'transport_identity' => 'different-provider',
    ]))->toThrow(QueryException::class);
    expect(fn () => DB::table('global_identity_operations')->where('id', $this->context->id)->update([
        'email_version' => 2,
    ]))->toThrow(QueryException::class);
});

it('quarantines restored delivery on a rejected checkpoint even if the caller has not quarantined it yet', function (): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $authenticator = Mockery::mock(GlobalIdentityJournalAuthenticator::class);
    $authenticator->shouldNotReceive('verifies');
    $restore = new GlobalIdentityOperationRestore($this->operations, $authenticator);

    expect($restore->reconcile((string) Str::uuid(), 10, 11, null))->toBeFalse();
    $this->worker->deliver($this->context->id);
    expect(GlobalIdentityOperation::query()->findOrFail($this->context->id)->quarantined)->toBeTrue()
        ->and($this->transport->sentKeys)->toBe([]);
});

it('requires a fresh authenticated contiguous terminal journal before applying restore tombstones', function (): void {
    $this->operations->create($this->context, now()->toImmutable()->addMinutes(10));
    $ambiguous = new GlobalIdentityOperationContext(Operation::EmailVerification, (string) Str::uuid(), $this->context->userId, 1);
    $this->operations->create($ambiguous, now()->toImmutable()->addMinutes(10));
    $claim = $this->operations->claim($ambiguous->id, 'fixture');
    expect($claim)->not->toBeNull()->and($this->operations->commitDispatch($claim))->toBeTrue();

    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $authenticator = new class(sodium_crypto_sign_publickey($pair)) implements GlobalIdentityJournalAuthenticator
    {
        public function __construct(private readonly string $publicKey) {}

        public function verifies(string $checkpoint, #[SensitiveParameter] string $authenticator): bool
        {
            return sodium_crypto_sign_verify_detached($authenticator, $checkpoint, $this->publicKey);
        }
    };
    $restore = new GlobalIdentityOperationRestore($this->operations, $authenticator);
    $restore->quarantine();
    $epoch = (string) Str::uuid();
    $source = GlobalIdentityOperation::query()->findOrFail($this->context->id);
    $key = new GlobalIdentityKeyReference($source->delivery_key_id, $source->delivery_key_version);
    $terminal = new GlobalIdentityTerminalJournalEntry(11, $source->id, State::Terminal, $key, now()->getTimestamp());
    $gap = new GlobalIdentityTerminalJournalEntry(12, $source->id, State::Terminal, $key, now()->getTimestamp());
    $signed = static function (int $end, array $entries) use ($epoch, $secret): GlobalIdentityJournalInterval {
        $interval = new GlobalIdentityJournalInterval($epoch, 10, $end, $entries, '');

        return new GlobalIdentityJournalInterval($epoch, 10, $end, $entries, sodium_crypto_sign_detached($interval->checkpoint(), $secret));
    };
    expect($restore->reconcile($epoch, 10, 11, null))->toBeFalse()
        ->and($restore->reconcile($epoch, 10, 12, $signed(11, [$terminal])))->toBeFalse()
        ->and($restore->reconcile($epoch, 10, 12, $signed(12, [$gap])))->toBeFalse()
        ->and($restore->reconcile($epoch, 10, 11, new GlobalIdentityJournalInterval($epoch, 10, 11, [$terminal], str_repeat('x', 64))))->toBeFalse();
    $this->worker->deliver($source->id);
    $this->worker->reconcile($ambiguous->id);
    expect($this->transport->sentKeys)->toBe([]);

    expect($restore->reconcile($epoch, 10, 11, $signed(11, [$terminal])))->toBeTrue();
    expect($source->fresh()->delivery_state)->toBe(State::Terminal)
        ->and($source->fresh()->delivery_token_enc)->toBeNull()
        ->and(GlobalIdentityOperation::query()->findOrFail($ambiguous->id)->quarantined)->toBeTrue();
    $this->worker->reconcile($ambiguous->id);
    expect($this->transport->sentKeys)->toBe([]);
    sodium_memzero($secret);
});

final class SyntheticOperationSubject implements GlobalIdentityOperationSubject
{
    public bool $current = true;

    public function isCurrent(GlobalIdentityOperationContext $operation): bool
    {
        expect(DB::transactionLevel())->toBeGreaterThan(0);

        return $this->current;
    }

    public function recipient(GlobalIdentityOperationContext $operation): string
    {
        expect(DB::transactionLevel())->toBe(0);

        return 'source@secpal.app';
    }
}

final class SyntheticIdentityRenderer implements GlobalIdentityDeliveryRenderer
{
    public string $token = '';

    public ?Closure $beforeReturn = null;

    public function render(GlobalIdentityOperationContext $operation, #[SensitiveParameter] string $token): string
    {
        expect(DB::transactionLevel())->toBe(0);
        $this->token = $token;
        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)();
        }

        return 'https://app.secpal.dev/fixture/'.$operation->id.'/'.$token;
    }
}

final class SyntheticIdentityTransport implements GlobalIdentityDeliveryTransport
{
    public Outcome $sendOutcome = Outcome::Accepted;

    public Outcome $lookupOutcome = Outcome::Unknown;

    public ?Outcome $referenceOutcome = null;

    public ?string $reference = null;

    public bool $keyAccepted = true;

    public string $providerIdentity = 'fixture';

    /** @var list<string> */
    public array $lookups = [];

    /** @var list<string|null> */
    public array $sentKeys = [];

    public ?Closure $inspect = null;

    public string $recipient = '';

    public function identity(): string
    {
        return $this->providerIdentity;
    }

    public function acceptsV1Key(): bool
    {
        return $this->keyAccepted;
    }

    public function permitsReplay(CarbonImmutable $firstCommit): bool
    {
        return true;
    }

    public function send(
        #[SensitiveParameter] string $recipient,
        #[SensitiveParameter] string $message,
        #[SensitiveParameter] ?string $key,
    ): GlobalIdentityDeliveryResult {
        expect(DB::transactionLevel())->toBe(0);
        $this->sentKeys[] = $key;
        $this->recipient = $recipient;
        if ($this->inspect !== null) {
            ($this->inspect)();
        }

        return new GlobalIdentityDeliveryResult($this->sendOutcome, $this->reference);
    }

    public function lookupReference(#[SensitiveParameter] string $reference): ?GlobalIdentityDeliveryResult
    {
        expect(DB::transactionLevel())->toBe(0);
        $this->lookups[] = 'reference';

        return $this->referenceOutcome === null ? null : new GlobalIdentityDeliveryResult($this->referenceOutcome);
    }

    public function lookupKey(#[SensitiveParameter] string $key): ?GlobalIdentityDeliveryResult
    {
        expect(DB::transactionLevel())->toBe(0);
        $this->lookups[] = 'key';

        return new GlobalIdentityDeliveryResult($this->lookupOutcome);
    }
}
