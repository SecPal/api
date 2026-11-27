<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Contracts\GlobalIdentityKeyWrapper;
use App\Contracts\GlobalIdentityRotationAuthority;
use App\Exceptions\GlobalIdentityKeyException;
use App\Models\User;
use App\Services\GlobalIdentity\FileGlobalIdentityKeyWrapper;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalEmailRepository;
use App\Services\GlobalIdentity\GlobalIdentityAccounts;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityRotationKind;
use App\Services\GlobalIdentity\GlobalIdentityRotationPlan;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose as Purpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use App\Services\GlobalIdentity\WrappedGlobalIdentityKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class, Tests\Support\ManagesGlobalIdentityFixture::class);

beforeEach(function (): void {
    $this->rootDirectory = sys_get_temp_dir().'/secpal-key-registry-'.bin2hex(random_bytes(12));
    mkdir($this->rootDirectory, 0700);
    $this->provider = new FileGlobalIdentityRootProvider($this->rootDirectory);
    $this->root = new GlobalIdentityKeyReference('root', 1);
    $this->provider->provision($this->root);
    $this->wrapper = new FileGlobalIdentityKeyWrapper($this->provider);
    $this->registry = new GlobalIdentityWorkingKeyRegistry($this->wrapper);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->rootDirectory);
});

function registryKeyDigest(#[SensitiveParameter] string $key): string
{
    return hash('sha256', $key);
}

it('initializes each required purpose once and stores only authenticated wrapped material', function (): void {
    $this->registry->initialize($this->root);
    $this->registry->initialize($this->root);

    $purposes = [
        'secpal/global-identity/email-encryption/v1',
        'secpal/global-identity/user-email-lookup/v1',
        'secpal/global-identity/user-email-reservation/v1',
        'secpal/global-identity/authentication-rate-limit/v1',
        'secpal/global-identity/operation-delivery-secret/v1',
        'secpal/global-identity/invitation-address-encryption/v1',
        'secpal/global-identity/pending-email-change-encryption/v1',
        'secpal/global-identity/mfa-credentials/v1',
    ];
    $digests = [];
    foreach ($purposes as $label) {
        $purpose = Purpose::from($label);
        [$context, $digest] = $this->registry->withActiveKey(
            $purpose,
            static fn (#[SensitiveParameter] string $key, GlobalIdentityWorkingKeyContext $context): array => [$context, registryKeyDigest($key)],
        );
        expect($context->purpose)->toBe($purpose)
            ->and($context->reference->version)->toBe(1)
            ->and($this->registry->withKey($context, registryKeyDigest(...)))->toBe($digest);
        $digests[] = $digest;
    }

    $rows = DB::table('global_identity_working_keys')->selectRaw('*, encode(wrapped_key, \'hex\') AS wrapped_hex')->get();
    expect($rows)->toHaveCount(count($purposes))
        ->and($rows->pluck('purpose')->all())->toEqualCanonicalizing($purposes)
        ->and($rows->pluck('key_id')->unique())->toHaveCount(count($purposes))
        ->and($rows->pluck('root_version')->unique()->all())->toBe([1])
        ->and($rows->pluck('state')->unique()->all())->toBe(['write-active'])
        ->and(array_unique($digests))->toHaveCount(count($purposes));
    foreach ($rows as $row) {
        expect($row->wrapped_hex)->toHaveLength(96)
            ->and($digests)->not->toContain(hash('sha256', hex2bin($row->wrapped_hex)));
    }
});

it('activates an authenticated candidate while keeping the former exact version readable', function (): void {
    $this->registry->initialize($this->root);
    $purpose = Purpose::EmailEncryption;
    $old = $this->registry->withActiveKey(
        $purpose,
        static fn (#[SensitiveParameter] string $key, GlobalIdentityWorkingKeyContext $context): GlobalIdentityWorkingKeyContext => $context,
    );
    $oldDigest = $this->registry->withKey($old, registryKeyDigest(...));
    $candidate = $this->registry->stage($purpose);

    expect($this->registry->stage($purpose))->toEqual($candidate)
        ->and($candidate->reference->id)->toBe($old->reference->id)
        ->and($candidate->reference->version)->toBe(2)
        ->and(fn () => $this->registry->withKey($candidate, registryKeyDigest(...)))->toThrow(GlobalIdentityKeyException::class)
        ->and($this->registry->withActiveKey($purpose, registryKeyDigest(...)))->toBe($oldDigest);

    expect(fn () => DB::transaction(fn () => DB::table('global_identity_working_keys')
        ->where('purpose', $purpose->value)->where('key_version', 2)
        ->update(['state' => 'write-active', 'activated_at' => now()])))
        ->toThrow(UniqueConstraintViolationException::class);

    $this->registry->activate($candidate);
    $this->registry->activate($candidate);

    expect($this->registry->withActiveKey($purpose, registryKeyDigest(...)))->not->toBe($oldDigest)
        ->and($this->registry->withKey($candidate, registryKeyDigest(...)))->toBe($this->registry->withActiveKey($purpose, registryKeyDigest(...)))
        ->and($this->registry->withKey($old, registryKeyDigest(...)))->toBe($oldDigest)
        ->and(fn () => $this->registry->activate($old))->toThrow(GlobalIdentityKeyException::class);
});

it('resumes root rewrap and preserves each exact working key', function (): void {
    $this->registry->initialize($this->root);
    $nextRoot = new GlobalIdentityKeyReference('root', 2);
    $this->provider->provision($nextRoot);
    $prior = DB::table('global_identity_working_keys')->orderBy('id')
        ->selectRaw("id, purpose, key_id, key_version, encode(wrapping_nonce, 'hex') AS nonce")->get();
    $digests = [];
    foreach ($prior as $row) {
        $context = new GlobalIdentityWorkingKeyContext(Purpose::from($row->purpose), new GlobalIdentityKeyReference($row->key_id, $row->key_version));
        $digests[$row->id] = $this->registry->withKey($context, registryKeyDigest(...));
    }

    expect($this->registry->rewrapRootBatch($this->root, $nextRoot, 3))->toBe(3)
        ->and(DB::table('global_identity_working_keys')->where('root_version', 2)->count())->toBe(3)
        ->and(DB::table('global_identity_working_keys')->where('root_version', 1)->count())->toBe(5);

    foreach ($prior as $row) {
        $context = new GlobalIdentityWorkingKeyContext(Purpose::from($row->purpose), new GlobalIdentityKeyReference($row->key_id, $row->key_version));
        expect($this->registry->withKey($context, registryKeyDigest(...)))->toBe($digests[$row->id]);
    }

    expect($this->registry->rewrapRootBatch($this->root, $nextRoot, 10))->toBe(5)
        ->and($this->registry->rewrapRootBatch($this->root, $nextRoot, 10))->toBe(0);
    foreach ($prior as $row) {
        $context = new GlobalIdentityWorkingKeyContext(Purpose::from($row->purpose), new GlobalIdentityKeyReference($row->key_id, $row->key_version));
        $rewrapped = DB::table('global_identity_working_keys')->where('id', $row->id)
            ->selectRaw("root_version, encode(wrapping_nonce, 'hex') AS nonce")->sole();
        expect($rewrapped->root_version)->toBe(2)
            ->and($rewrapped->nonce)->not->toBe($row->nonce)
            ->and($this->registry->withKey($context, registryKeyDigest(...)))->toBe($digests[$row->id]);
    }
});

it('advances an approved root rotation in independently committed command batches', function (): void {
    $this->registry->initialize($this->root);
    $target = new GlobalIdentityKeyReference('root', 2);
    $this->provider->provision($target);
    app()->instance(GlobalIdentityWorkingKeyRegistry::class, $this->registry);
    $id = (string) Str::uuid();
    $plan = new GlobalIdentityRotationPlan($id, GlobalIdentityRotationKind::Root, $this->root, $target);
    $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
    $authority->shouldReceive('approvedPlan')->with($id, 'start')->once()->andReturn($plan);
    $authority->shouldReceive('approvedPlan')->with($id, 'advance')->times(3)->andReturn($plan);
    $authority->shouldReceive('approvedPlan')->with($id, 'pause')->once()->andReturn($plan);
    $authority->shouldReceive('approvedPlan')->with($id, 'resume')->once()->andReturn($plan);
    $authority->shouldReceive('approvedPlan')->with($id, 'complete')->twice()->andReturn($plan);
    app()->instance(GlobalIdentityRotationAuthority::class, $authority);

    $arguments = ['operation' => $id, '--batch' => 3];
    $this->artisan('identity:rotation', $arguments + ['action' => 'start'])->assertSuccessful();
    $this->artisan('identity:rotation', $arguments + ['action' => 'advance'])->assertSuccessful();
    expect(DB::table('global_identity_working_keys')->where('root_version', 2)->count())->toBe(3);

    $this->artisan('identity:rotation', $arguments + ['action' => 'pause'])->assertSuccessful();
    expect(DB::table('global_identity_rotations')->where('id', $id)->value('phase'))->toBe('paused');
    $this->artisan('identity:rotation', $arguments + ['action' => 'resume'])->assertSuccessful();
    $this->artisan('identity:rotation', $arguments + ['action' => 'advance'])->assertSuccessful();
    expect(DB::table('global_identity_working_keys')->where('root_version', 2)->count())->toBe(6);
    $this->artisan('identity:rotation', $arguments + ['action' => 'complete'])->assertFailed();
    expect(DB::table('global_identity_working_keys')->where('root_version', 1)->count())->toBe(2);
    $this->artisan('identity:rotation', $arguments + ['action' => 'advance'])->assertSuccessful();
    $this->artisan('identity:rotation', $arguments + ['action' => 'complete'])->assertSuccessful();
    expect(DB::table('global_identity_rotations')->where('id', $id)->value('phase'))->toBe('complete');
});

it('keeps a partial root rewrap batch intact when an exact target root is unavailable', function (): void {
    $this->registry->initialize($this->root);
    $next = new GlobalIdentityKeyReference('root', 2);
    $this->provider->provision($next);
    expect($this->registry->rewrapRootBatch($this->root, $next, 2))->toBe(2);

    rename($this->rootDirectory.'/root.2.key', $this->rootDirectory.'/offline.key');
    expect(fn () => $this->registry->rewrapRootBatch($this->root, $next, 3))->toThrow(GlobalIdentityKeyException::class)
        ->and(DB::table('global_identity_working_keys')->where('root_version', 2)->count())->toBe(2);

    rename($this->rootDirectory.'/offline.key', $this->rootDirectory.'/root.2.key');
    expect($this->registry->rewrapRootBatch($this->root, $next, 10))->toBe(6);
});

it('rejects starting another root rewrap while a prior root checkpoint is paused', function (): void {
    $this->registry->initialize($this->root);
    $target = new GlobalIdentityKeyReference('root', 2);
    $laterTarget = new GlobalIdentityKeyReference('root', 3);
    $this->provider->provision($target);
    $this->provider->provision($laterTarget);
    app()->instance(GlobalIdentityWorkingKeyRegistry::class, $this->registry);
    $first = (string) Str::uuid();
    $later = (string) Str::uuid();
    $authority = Mockery::mock(GlobalIdentityRotationAuthority::class);
    $authority->shouldReceive('approvedPlan')->with($first, Mockery::type('string'))
        ->andReturn(new GlobalIdentityRotationPlan($first, GlobalIdentityRotationKind::Root, $this->root, $target));
    $authority->shouldReceive('approvedPlan')->with($later, 'start')
        ->andReturn(new GlobalIdentityRotationPlan($later, GlobalIdentityRotationKind::Root, $target, $laterTarget));
    app()->instance(GlobalIdentityRotationAuthority::class, $authority);
    $this->artisan('identity:rotation', ['operation' => $first, 'action' => 'start'])->assertSuccessful();
    $this->artisan('identity:rotation', ['operation' => $first, 'action' => 'advance', '--batch' => 3])->assertSuccessful();
    $this->artisan('identity:rotation', ['operation' => $first, 'action' => 'pause'])->assertSuccessful();
    $this->artisan('identity:rotation', ['operation' => $later, 'action' => 'start'])->assertFailed();

    expect(DB::table('global_identity_rotations')->count())->toBe(1)
        ->and(DB::table('global_identity_working_keys')->where('root_version', 2)->count())->toBe(3);
    $this->artisan('identity:rotation', ['operation' => $first, 'action' => 'resume'])->assertSuccessful();
    $this->artisan('identity:rotation', ['operation' => $first, 'action' => 'advance'])->assertSuccessful();
    $this->artisan('identity:rotation', ['operation' => $first, 'action' => 'complete'])->assertSuccessful();
});

it('opens restored encrypted identity state before, during and after root rewrap using only the exact roots', function (): void {
    $directory = $this->rootDirectory;
    $this->registry->initialize($this->root);
    $target = new GlobalIdentityKeyReference('root', 2);
    $this->provider->provision($target);
    app()->instance(GlobalIdentityWorkingKeyRegistry::class, $this->registry);
    $accounts = app(GlobalIdentityAccounts::class);
    $email = app(GlobalEmailRepository::class);
    $address = 'Recovery+Checkpoint@secpal.dev';
    $user = User::factory()->create(['email' => $address]);

    DB::statement('CREATE TEMP TABLE root_before ON COMMIT DROP AS TABLE global_identity_working_keys');
    $this->registry->rewrapRootBatch($this->root, $target, 3);
    DB::statement('CREATE TEMP TABLE root_during ON COMMIT DROP AS TABLE global_identity_working_keys');
    $this->registry->rewrapRootBatch($this->root, $target, 10);
    DB::statement('CREATE TEMP TABLE root_after ON COMMIT DROP AS TABLE global_identity_working_keys');

    foreach (['root_before' => [1], 'root_during' => [1, 2], 'root_after' => [2]] as $snapshot => $roots) {
        DB::beginTransaction();
        try {
            DB::table('global_identity_working_keys')->delete();
            DB::statement('INSERT INTO global_identity_working_keys TABLE '.$snapshot);
            $omitted = count($roots) === 2 ? null : $directory.'/root.'.(3 - $roots[0]).'.key';
            if ($omitted !== null) {
                rename($omitted, $directory.'/offline.key');
            }
            try {
                expect($accounts->find($address)?->id)->toBe($user->id)
                    ->and($email->withEmail($user->id, 1, false, static fn (#[SensitiveParameter] string $value): string => $value))->toBe($address)
                    ->and(DB::table('global_identity_working_keys')->distinct()->orderBy('root_version')->pluck('root_version')->all())->toBe($roots);
                $required = $directory.'/root.'.$roots[0].'.key';
                $purpose = Purpose::from(DB::table('global_identity_working_keys')->where('root_version', $roots[0])->value('purpose'));
                rename($required, $directory.'/required.key');
                try {
                    expect(fn () => $this->registry->withActiveKey($purpose, registryKeyDigest(...)))
                        ->toThrow(GlobalIdentityKeyException::class);
                } finally {
                    rename($directory.'/required.key', $required);
                }
            } finally {
                if ($omitted !== null) {
                    rename($directory.'/offline.key', $omitted);
                }
            }
        } finally {
            DB::rollBack();
        }
    }
});

it('fails closed on substituted roots and purposes and only audits bounded metadata', function (): void {
    $this->registry->initialize($this->root);
    $purpose = Purpose::EmailEncryption;
    $context = $this->registry->withActiveKey(
        $purpose,
        static fn (#[SensitiveParameter] string $key, GlobalIdentityWorkingKeyContext $context): GlobalIdentityWorkingKeyContext => $context,
    );

    Log::spy();
    $wrongPurpose = new GlobalIdentityWorkingKeyContext(Purpose::UserEmailLookup, $context->reference);
    expect(fn () => $this->registry->withKey($wrongPurpose, registryKeyDigest(...)))->toThrow(GlobalIdentityKeyException::class);

    // Reuse the root bytes under another version to isolate the authenticated reference check.
    $replacement = $this->rootDirectory.'/root.2.key';
    copy($this->rootDirectory.'/root.1.key', $replacement);
    chmod($replacement, 0600);
    DB::table('global_identity_working_keys')->where('purpose', $purpose->value)->update(['root_version' => 2]);

    expect(fn () => $this->registry->withKey($context, registryKeyDigest(...)))->toThrow(GlobalIdentityKeyException::class);
    Log::shouldHaveReceived('warning')->with('Global identity working key rejected.', [
        'operation' => 'resolve',
        'reason' => 'invalid_envelope',
        'purpose' => $purpose->value,
    ])->once();
});

it('rolls back initialization when wrapping fails partway through', function (): void {
    $wrapper = new class($this->wrapper) implements GlobalIdentityKeyWrapper
    {
        private int $wraps = 0;

        public function __construct(private readonly GlobalIdentityKeyWrapper $delegate) {}

        public function wrap(
            #[SensitiveParameter] string $key,
            GlobalIdentityWorkingKeyContext $workingKey,
            GlobalIdentityKeyReference $root,
        ): WrappedGlobalIdentityKey {
            if (++$this->wraps === 2) {
                throw new GlobalIdentityKeyException('Synthetic wrapping failure.');
            }

            return $this->delegate->wrap($key, $workingKey, $root);
        }

        public function unwrap(
            #[SensitiveParameter] WrappedGlobalIdentityKey $wrapped,
            GlobalIdentityWorkingKeyContext $workingKey,
        ): string {
            return $this->delegate->unwrap($wrapped, $workingKey);
        }
    };

    expect(fn () => (new GlobalIdentityWorkingKeyRegistry($wrapper))->initialize($this->root))
        ->toThrow(GlobalIdentityKeyException::class)
        ->and(DB::table('global_identity_working_keys')->count())->toBe(0);

    $this->registry->initialize($this->root);
    expect(DB::table('global_identity_working_keys')->count())->toBe(count(Purpose::cases()));
});

it('resolves through the Laravel container and rejects a missing exact working version', function (): void {
    config(['global_identity.root_directory' => $this->rootDirectory]);
    $registry = app(GlobalIdentityWorkingKeyRegistry::class);
    $registry->initialize($this->root);

    Log::spy();
    $unknown = new GlobalIdentityWorkingKeyContext(Purpose::EmailEncryption, new GlobalIdentityKeyReference('unknown', 1));
    expect(fn () => $registry->withKey($unknown, registryKeyDigest(...)))->toThrow(GlobalIdentityKeyException::class);
    Log::shouldHaveReceived('warning')->with('Global identity working key rejected.', [
        'operation' => 'resolve',
        'reason' => 'missing_version',
        'purpose' => Purpose::EmailEncryption->value,
    ])->once();
});
