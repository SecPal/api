<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('tenant key can be created with factory', function (): void {
    $tenantKey = TenantKey::factory()->create();

    expect($tenantKey->id)->not->toBeNull()
        ->and($tenantKey->dek_wrapped)->not->toBeNull()
        ->and($tenantKey->dek_nonce)->not->toBeNull()
        ->and($tenantKey->idx_wrapped)->not->toBeNull()
        ->and($tenantKey->idx_nonce)->not->toBeNull()
        ->and($tenantKey->key_version)->toBe(1)
        ->and($tenantKey->created_at)->not->toBeNull();
});

test('tenant key factory generates valid envelope keys', function (): void {
    $tenantKey = TenantKey::factory()->create();

    // Verify that keys can be unwrapped successfully
    $dek = $tenantKey->unwrapDek();
    expect(strlen($dek))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    sodium_memzero($dek);

    $idxKey = $tenantKey->unwrapIdxKey();
    expect(strlen($idxKey))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    sodium_memzero($idxKey);
});

test('tenant key factory creates the kek directory when it is missing', function (): void {
    $keysDirectory = storage_path('app/keys/tenant-key-test-'.getmypid().'-'.uniqid('', true));
    TenantKey::setKekPath($keysDirectory.'/kek.key');

    expect(is_dir($keysDirectory))->toBeFalse();

    $tenantKey = TenantKey::factory()->create();

    expect($tenantKey->exists)->toBeTrue()
        ->and(is_dir($keysDirectory))->toBeTrue()
        ->and(file_exists(TenantKey::getKekPath()))->toBeTrue();
});

test('load kek rejects insecure file permissions', function (): void {
    TenantKey::generateKek();

    chmod(TenantKey::getKekPath(), 0644);

    expect(fn (): string => TenantKey::loadKek())
        ->toThrow(RuntimeException::class, 'KEK file has insecure permissions');
});

test('generate kek ignores a permissive process umask and restores it afterwards', function (): void {
    $previousUmask = umask(0000);

    try {
        TenantKey::generateKek();

        $kekPath = TenantKey::getKekPath();
        clearstatcache(true, $kekPath);

        expect(fileperms($kekPath) & 0777)->toBe(0600)
            ->and(umask())->toBe(0000);
    } finally {
        umask($previousUmask);
    }
});

test('tenant key factory can create with specific version', function (): void {
    $tenantKey = TenantKey::factory()->version(5)->create();

    expect($tenantKey->key_version)->toBe(5);
});

test('tenant key factory creates unique keys for each instance', function (): void {
    $tenantKey1 = TenantKey::factory()->create();
    $tenantKey2 = TenantKey::factory()->create();

    expect($tenantKey1->dek_wrapped)->not->toEqual($tenantKey2->dek_wrapped)
        ->and($tenantKey1->dek_nonce)->not->toEqual($tenantKey2->dek_nonce)
        ->and($tenantKey1->idx_wrapped)->not->toEqual($tenantKey2->idx_wrapped)
        ->and($tenantKey1->idx_nonce)->not->toEqual($tenantKey2->idx_nonce);
});

test('tenant key factory keys are functional for encryption', function (): void {
    $tenantKey = TenantKey::factory()->create();

    $plaintext = 'Sensitive Data';
    $encrypted = $tenantKey->encrypt($plaintext);

    expect($encrypted)->toHaveKey('ciphertext')
        ->toHaveKey('nonce')
        ->and($encrypted['ciphertext'])->not->toEqual($plaintext);

    $decrypted = $tenantKey->decrypt($encrypted['ciphertext'], $encrypted['nonce']);
    expect($decrypted)->toBe($plaintext);
});

test('tenant key factory keys are functional for blind index', function (): void {
    $tenantKey = TenantKey::factory()->create();

    $plaintext = 'searchable-value';
    $index = $tenantKey->generateBlindIndex($plaintext);

    expect($index)->not->toBeNull()
        ->and(strlen($index))->toBe(32); // HMAC-SHA256 produces 32 bytes

    // Same plaintext should produce same index
    $index2 = $tenantKey->generateBlindIndex($plaintext);
    expect($index)->toBe($index2);

    // Different plaintext should produce different index
    $index3 = $tenantKey->generateBlindIndex('different-value');
    expect($index)->not->toBe($index3);
});

// ============================================================================
// Parallel-Safety Tests (Issue #1106)
// ============================================================================

test('ensureKekExists creates the KEK when missing', function (): void {
    $kekPath = TenantKey::getKekPath();
    expect(file_exists($kekPath))->toBeFalse();

    TenantKey::ensureKekExists();

    expect(file_exists($kekPath))->toBeTrue()
        ->and(filesize($kekPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        ->and(fileperms($kekPath) & 0777)->toBe(0600);
})->group('parallel-safety', 'issue-1106');

test('ensureKekExists is idempotent and preserves existing KEK bytes', function (): void {
    TenantKey::ensureKekExists();

    $kekPath = TenantKey::getKekPath();
    $originalBytes = file_get_contents($kekPath);
    $originalInode = fileinode($kekPath);

    // Second call must not overwrite or recreate the file.
    TenantKey::ensureKekExists();

    clearstatcache(true, $kekPath);

    expect(file_get_contents($kekPath))->toBe($originalBytes)
        ->and(fileinode($kekPath))->toBe($originalInode);
})->group('parallel-safety', 'issue-1106');

test('ensureKekExists is a no-op when the KEK already exists', function (): void {
    $kekPath = TenantKey::getKekPath();

    // Pre-populate with a known KEK byte pattern. ensureKekExists() must not
    // touch it, because overwriting an in-use KEK would corrupt every tenant.
    @mkdir(dirname($kekPath), 0700, true);
    $marker = str_repeat("\x42", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    file_put_contents($kekPath, $marker);
    chmod($kekPath, 0600);

    TenantKey::ensureKekExists();

    expect(file_get_contents($kekPath))->toBe($marker);
})->group('parallel-safety', 'issue-1106');

test('ensureKekExists rejects a pre-existing file with wrong size before returning', function (): void {
    $kekPath = TenantKey::getKekPath();

    // Pre-populate the canonical path with a too-short payload (e.g. a stale
    // leftover from a crashed write or a manually-placed file). The early-
    // return path must NOT silently accept it -- doing so would let downstream
    // loadKek() throw a generic "Invalid KEK file" instead of pointing at the
    // real problem.
    @mkdir(dirname($kekPath), 0700, true);
    file_put_contents($kekPath, str_repeat("\x00", 8));
    chmod($kekPath, 0600);

    expect(fn (): null => TenantKey::ensureKekExists() ?? null)
        ->toThrow(RuntimeException::class, 'KEK file has invalid size');
})->group('parallel-safety', 'issue-1106');

test('ensureKekExists rejects a pre-existing file with insecure permissions', function (): void {
    $kekPath = TenantKey::getKekPath();

    // World-readable KEK files are a security incident. The early-return
    // path must refuse to trust them instead of silently treating them as
    // valid just because file_exists() is true.
    @mkdir(dirname($kekPath), 0700, true);
    file_put_contents($kekPath, str_repeat("\x42", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    chmod($kekPath, 0644);

    expect(fn (): null => TenantKey::ensureKekExists() ?? null)
        ->toThrow(RuntimeException::class, 'KEK file has insecure permissions');
})->group('parallel-safety', 'issue-1106');

test('ensureKekExists publishes the canonical path atomically (loadKek-safe)', function (): void {
    $uniqueSuffix = getmypid().'-'.uniqid('', true);
    $temporaryDirectory = sys_get_temp_dir().'/kek-publish-'.$uniqueSuffix;
    $kekPath = $temporaryDirectory.'/kek.key';
    $simulatedConcurrentTempPath = $temporaryDirectory.'/.kek-tmp-'.$uniqueSuffix;

    @mkdir($temporaryDirectory, 0700, true);
    file_put_contents($simulatedConcurrentTempPath, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    TenantKey::setKekPath($kekPath);

    // Atomic publish guarantee: once ensureKekExists() returns successfully
    // the canonical path is a complete, KEYBYTES-sized, 0600 KEK that
    // loadKek() will accept. This prevents the race that callers like
    // TenantKeyFactory hit when they chain ensureKekExists() -> loadKek()
    // and a partial/zero-length file would make loadKek() throw.
    try {
        TenantKey::ensureKekExists();

        expect(filesize($kekPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
            ->and(fileperms($kekPath) & 0777)->toBe(0600);

        // loadKek() must accept the just-published file without retry/wait.
        $kek = TenantKey::loadKek();

        expect(strlen($kek))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        sodium_memzero($kek);

        // A concurrent worker can own a temporary KEK in the same directory.
        // This invocation must remove only its own temporary KEK, leaving the
        // concurrent worker's file in place.
        expect(glob($temporaryDirectory.'/.kek-tmp-*') ?: [])
            ->toBe([$simulatedConcurrentTempPath]);
    } finally {
        @unlink($kekPath);
        @unlink($simulatedConcurrentTempPath);
        foreach (glob($temporaryDirectory.'/.kek-tmp-*') ?: [] as $temporaryKekPath) {
            @unlink($temporaryKekPath);
        }
        @rmdir($temporaryDirectory);
        TenantKey::setKekPath(null);
    }
})->group('parallel-safety', 'issue-1106');

test('generateKek still refuses to overwrite an existing KEK', function (): void {
    TenantKey::generateKek();

    expect(fn () => TenantKey::generateKek())
        ->toThrow(RuntimeException::class, 'KEK file already exists');
})->group('parallel-safety', 'issue-1106');

test('tryCreateKekFile surfaces real failures with the underlying error message', function (): void {
    // A symlink pointing at a target in a non-existent parent directory
    // reproduces the "publish failed for a non-race reason" branch in a
    // single process:
    //   * file_exists($path) returns false (symlink target does not exist)
    //   * the atomic link() into $path fails because the link already
    //     occupies that name, and the recheck still reports the target as
    //     missing — so this is a real failure, not a race we should swallow.
    // ensureKekExists() must surface a clear RuntimeException with the
    // underlying PHP error attached instead of leaving callers with a raw
    // fopen/link warning or a silent success.
    $uniqueSuffix = getmypid().'-'.uniqid('', true);
    $temporaryDirectory = sys_get_temp_dir().'/kek-dangling-'.$uniqueSuffix;
    $kekPath = $temporaryDirectory.'/kek.key';
    $danglingTarget = '/nonexistent/'.$uniqueSuffix.'/target.key';

    @mkdir($temporaryDirectory, 0700, true);
    @unlink($kekPath);

    if (! @symlink($danglingTarget, $kekPath)) {
        @rmdir($temporaryDirectory);

        $this->markTestSkipped('Unable to create symlink for race-loser path test.');
    }

    TenantKey::setKekPath($kekPath);

    try {
        expect(fn (): null => TenantKey::ensureKekExists() ?? null)
            ->toThrow(RuntimeException::class, 'Failed to publish KEK file at:');

        // The failed publish path owns and must clean up its temporary KEK.
        // A dedicated directory keeps this assertion independent of parallel
        // workers publishing their own KEKs elsewhere.
        expect(glob($temporaryDirectory.'/.kek-tmp-*') ?: [])->toBe([]);
    } finally {
        @unlink($kekPath);
        foreach (glob($temporaryDirectory.'/.kek-tmp-*') ?: [] as $temporaryKekPath) {
            @unlink($temporaryKekPath);
        }
        @rmdir($temporaryDirectory);
        TenantKey::setKekPath(null);
    }
})->group('parallel-safety', 'issue-1106');

test('concurrent ensureKekExists calls survive the create race', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl + posix extensions required to simulate concurrent KEK creation.');
    }

    // Use a dedicated path so we can reliably observe the race outcome and
    // avoid interfering with any other tests' KEK isolation helpers.
    $uniqueSuffix = getmypid().'-'.uniqid('', true);
    $kekPath = storage_path('app/keys/kek-race-'.$uniqueSuffix.'.key');
    $statusDir = sys_get_temp_dir().'/kek-race-'.$uniqueSuffix;
    @mkdir($statusDir, 0700, true);
    @unlink($kekPath);
    TenantKey::setKekPath($kekPath);

    $workerCount = 8;
    $pids = [];

    try {
        for ($i = 0; $i < $workerCount; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Failed to fork worker process.');
            }

            if ($pid === 0) {
                // Child: only race on the KEK file. We SIGKILL ourselves on
                // exit because PHP's shutdown handlers would otherwise close
                // the inherited PDO connection and break the parent runner's
                // RefreshDatabase rollback. A marker file communicates success.
                $marker = $statusDir.'/worker-'.posix_getpid().'.ok';

                try {
                    TenantKey::ensureKekExists();
                    @touch($marker);
                } catch (Throwable) {
                    // No marker on failure; parent will detect via the count.
                }

                posix_kill(posix_getpid(), SIGKILL);
                exit(0); // never reached; guards against signal delivery delay
            }

            $pids[] = $pid;
        }

        foreach ($pids as $childPid) {
            pcntl_waitpid($childPid, $status);
        }

        $successMarkers = glob($statusDir.'/worker-*.ok') ?: [];

        expect(count($successMarkers))->toBe($workerCount)
            ->and(file_exists($kekPath))->toBeTrue()
            ->and(filesize($kekPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
            ->and(fileperms($kekPath) & 0777)->toBe(0600);
    } finally {
        @unlink($kekPath);

        foreach (glob($statusDir.'/*') ?: [] as $marker) {
            @unlink($marker);
        }
        @rmdir($statusDir);

        TenantKey::setKekPath(null);
    }
})->group('parallel-safety', 'issue-1106');
