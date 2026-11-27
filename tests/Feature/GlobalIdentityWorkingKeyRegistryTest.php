<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Contracts\GlobalIdentityKeyWrapper;
use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\FileGlobalIdentityKeyWrapper;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose as Purpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use App\Services\GlobalIdentity\WrappedGlobalIdentityKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
