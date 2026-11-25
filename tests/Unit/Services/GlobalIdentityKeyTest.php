<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\FileGlobalIdentityKeyWrapper;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose;
use App\Services\GlobalIdentity\WrappedGlobalIdentityKey;
use Illuminate\Filesystem\Filesystem;
use Random\Engine;
use Random\RandomException;
use Random\Randomizer;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->rootDirectory = realpath(sys_get_temp_dir()).'/secpal-identity-'.bin2hex(random_bytes(12));
    mkdir($this->rootDirectory, 0700);
    $this->provider = new FileGlobalIdentityRootProvider($this->rootDirectory);
    $this->wrapper = new FileGlobalIdentityKeyWrapper($this->provider);
    $this->root = new GlobalIdentityKeyReference('root', 1);
    $this->workingKey = new GlobalIdentityWorkingKeyContext(
        GlobalIdentityWorkingKeyPurpose::EmailEncryption,
        new GlobalIdentityKeyReference('working', 7),
    );
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->rootDirectory);
});

it('wraps with fresh nonces and opens envelopes from retained root versions independently', function (): void {
    $newRoot = new GlobalIdentityKeyReference('root', 2);
    $this->provider->provision($this->root);
    $key = random_bytes(32);
    $oldEnvelope = $this->wrapper->wrap($key, $this->workingKey, $this->root);

    $this->provider->provision($newRoot);
    $newEnvelope = $this->wrapper->wrap($key, $this->workingKey, $newRoot);
    $retryEnvelope = $this->wrapper->wrap($key, $this->workingKey, $newRoot);

    expect($oldEnvelope->root)->toEqual($this->root)
        ->and($newEnvelope->root)->toEqual($newRoot)
        ->and(strlen($newEnvelope->nonce))->toBe(24)
        ->and($retryEnvelope->nonce)->not->toBe($newEnvelope->nonce);

    foreach ([$oldEnvelope, $newEnvelope, $retryEnvelope] as $envelope) {
        expect($this->wrapper->unwrap($envelope, $this->workingKey))->toBe($key);
    }

    expect($this->provider->load($this->root))->not->toBe($this->provider->load($newRoot));
    unlink($this->rootDirectory.'/root.1.key');

    expect(fn () => $this->wrapper->unwrap($oldEnvelope, $this->workingKey))
        ->toThrow(GlobalIdentityKeyException::class, 'Global identity root unavailable.');
    expect($this->wrapper->unwrap($newEnvelope, $this->workingKey))->toBe($key);
});

it('opens the V1 length-delimited wrapping vector', function (): void {
    $root = hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f');
    $path = $this->rootDirectory.'/root.1.key';
    file_put_contents($path, $root);
    chmod($path, 0400);
    $envelope = new WrappedGlobalIdentityKey(
        1,
        'XCHACHA20-POLY1305-IETF',
        $this->root,
        hex2bin('000102030405060708090a0b0c0d0e0f1011121314151617'),
        hex2bin('bee32d5cb4f7ab891b6d0ce5e77f86c77b761a96c9e32bdc4723058d0190cb6659d2b798156b5eee95ebde29a4c07421'),
    );

    expect(bin2hex($this->wrapper->unwrap($envelope, $this->workingKey)))
        ->toBe('202122232425262728292a2b2c2d2e2f303132333435363738393a3b3c3d3e3f');

    $nonceSource = new Randomizer(new class($envelope->nonce) implements Engine
    {
        private int $calls = 0;

        public function __construct(private readonly string $firstNonce) {}

        public function generate(): string
        {
            return match ($call = $this->calls++) {
                0, 1, 2 => substr($this->firstNonce, $call * 8, 8),
                3 => throw new RandomException('Synthetic entropy failure detail'),
                default => str_repeat("\xff", 8),
            };
        }
    });
    $wrapper = new FileGlobalIdentityKeyWrapper($this->provider, $nonceSource);
    $key = hex2bin('202122232425262728292a2b2c2d2e2f303132333435363738393a3b3c3d3e3f');

    expect($wrapper->wrap($key, $this->workingKey, $this->root))->toEqual($envelope);
    expect(fn () => $wrapper->wrap($key, $this->workingKey, $this->root))
        ->toThrow(GlobalIdentityKeyException::class, 'Global identity key wrapping failed.');
    expect($wrapper->wrap($key, $this->workingKey, $this->root)->nonce)->toBe(str_repeat("\xff", 24));
});

it('authenticates every part of the working-key and root context', function (): void {
    $this->provider->provision($this->root);
    $key = random_bytes(32);
    $envelope = $this->wrapper->wrap($key, $this->workingKey, $this->root);

    $wrongContexts = [
        new GlobalIdentityWorkingKeyContext(GlobalIdentityWorkingKeyPurpose::UserEmailLookup, $this->workingKey->reference),
        new GlobalIdentityWorkingKeyContext($this->workingKey->purpose, new GlobalIdentityKeyReference('another', 7)),
        new GlobalIdentityWorkingKeyContext($this->workingKey->purpose, new GlobalIdentityKeyReference('working', 8)),
    ];
    foreach ($wrongContexts as $context) {
        expect(fn () => $this->wrapper->unwrap($envelope, $context))
            ->toThrow(GlobalIdentityKeyException::class, 'Global identity key authentication failed.');
    }

    // Keep the bytes constant so that only the authenticated root reference differs.
    foreach ([new GlobalIdentityKeyReference('other', 1), new GlobalIdentityKeyReference('root', 2)] as $root) {
        $path = $this->rootDirectory.'/'.$root->id.'.'.$root->version.'.key';
        copy($this->rootDirectory.'/root.1.key', $path);
        chmod($path, 0600);
        $substituted = new WrappedGlobalIdentityKey(1, $envelope->algorithm, $root, $envelope->nonce, $envelope->ciphertextAndTag);

        expect(fn () => $this->wrapper->unwrap($substituted, $this->workingKey))
            ->toThrow(GlobalIdentityKeyException::class, 'Global identity key authentication failed.');
    }
});

it('rejects malformed envelopes, tampering, and a corrupt exact root', function (): void {
    $this->provider->provision($this->root);
    $key = random_bytes(32);
    $envelope = $this->wrapper->wrap($key, $this->workingKey, $this->root);
    $corrupt = $envelope->ciphertextAndTag;
    $corrupt[47] = $corrupt[47] ^ "\x01";

    foreach ([
        new WrappedGlobalIdentityKey(2, $envelope->algorithm, $this->root, $envelope->nonce, $envelope->ciphertextAndTag),
        new WrappedGlobalIdentityKey(1, 'unknown', $this->root, $envelope->nonce, $envelope->ciphertextAndTag),
        new WrappedGlobalIdentityKey(1, $envelope->algorithm, $this->root, substr($envelope->nonce, 1), $envelope->ciphertextAndTag),
        new WrappedGlobalIdentityKey(1, $envelope->algorithm, $this->root, $envelope->nonce, substr($envelope->ciphertextAndTag, 1)),
    ] as $malformed) {
        expect(fn () => $this->wrapper->unwrap($malformed, $this->workingKey))
            ->toThrow(GlobalIdentityKeyException::class, 'Invalid wrapped global identity key.');
    }

    $tampered = new WrappedGlobalIdentityKey(1, $envelope->algorithm, $this->root, $envelope->nonce, $corrupt);
    expect(fn () => $this->wrapper->unwrap($tampered, $this->workingKey))
        ->toThrow(GlobalIdentityKeyException::class, 'Global identity key authentication failed.');

    file_put_contents($this->rootDirectory.'/root.1.key', random_bytes(32));
    expect(fn () => $this->wrapper->unwrap($envelope, $this->workingKey))
        ->toThrow(GlobalIdentityKeyException::class, 'Global identity key authentication failed.');
});

it('rejects unsafe paths, file types, permissions, and lengths', function (): void {
    $path = $this->rootDirectory.'/root.1.key';
    expect(fn () => $this->provider->load($this->root))->toThrow(GlobalIdentityKeyException::class);
    foreach ([31, 33] as $length) {
        file_put_contents($path, random_bytes($length));
        chmod($path, 0600);
        expect(fn () => $this->provider->load($this->root))->toThrow(GlobalIdentityKeyException::class);
    }

    file_put_contents($path, random_bytes(32));
    chmod($path, 0640);
    expect(fn () => $this->provider->load($this->root))->toThrow(GlobalIdentityKeyException::class);
    chmod($path, 0600);
    chmod($this->rootDirectory, 0750);
    expect(fn () => $this->provider->load($this->root))->toThrow(GlobalIdentityKeyException::class);
    expect(fn () => $this->provider->provision(new GlobalIdentityKeyReference('root', 2)))
        ->toThrow(GlobalIdentityKeyException::class);
    chmod($this->rootDirectory, 0700);

    rename($path, $this->rootDirectory.'/target');
    symlink('target', $path);
    expect(fn () => $this->provider->load($this->root))->toThrow(GlobalIdentityKeyException::class);
    unlink($path);
    mkdir($path, 0700);
    expect(fn () => $this->provider->load($this->root))->toThrow(GlobalIdentityKeyException::class);

    symlink('.', $this->rootDirectory.'/alias');
    $symlinkedDirectory = new FileGlobalIdentityRootProvider($this->rootDirectory.'/alias');
    expect(fn () => $symlinkedDirectory->provision($this->root))->toThrow(GlobalIdentityKeyException::class);
    expect(fn () => new GlobalIdentityKeyReference('../root', 1))->toThrow(GlobalIdentityKeyException::class);
    expect(fn () => new GlobalIdentityKeyReference('root', 0))->toThrow(GlobalIdentityKeyException::class);
});

it('publishes a complete root once across competing processes without overwriting it', function (): void {
    $script = <<<'PHP'
        require $argv[1];
        $provider = new App\Services\GlobalIdentity\FileGlobalIdentityRootProvider($argv[2]);
        $root = new App\Services\GlobalIdentity\GlobalIdentityKeyReference('root', 1);
        fwrite(STDOUT, 'ready');
        while (!file_exists($argv[2].'/start')) {
            usleep(1000);
        }
        try {
            $provider->provision($root);
        } catch (App\Exceptions\GlobalIdentityKeyException $failure) {
            fwrite(STDERR, $failure->getMessage());
            exit(1);
        }
        PHP;
    $processes = [];
    try {
        foreach ([1, 2] as $index) {
            $processes[$index] = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $this->rootDirectory], timeout: 10);
            $processes[$index]->start();
            expect($processes[$index]->waitUntil(fn (string $type, string $output): bool => $output === 'ready'))->toBeTrue();
        }
        touch($this->rootDirectory.'/start');
        $results = array_map(fn (Process $process): int => $process->wait(), $processes);
        sort($results);

        expect($results)->toBe([0, 1]);
        $path = $this->rootDirectory.'/root.1.key';
        $published = $this->provider->load($this->root);
        expect(strlen($published))->toBe(32)
            ->and(fileperms($path) & 0777)->toBe(0600)
            ->and(scandir($this->rootDirectory))->toBe(['.', '..', 'root.1.key', 'start']);
        expect(fn () => $this->provider->provision($this->root))->toThrow(GlobalIdentityKeyException::class);
        expect($this->provider->load($this->root))->toBe($published);
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
    }
});

it('redacts secrets and paths from failures and never falls back to application or tenant keys', function (): void {
    $key = 'synthetic-do-not-log-working-key';
    config(['app.key' => $key, 'app.kek_path' => $this->rootDirectory.'/root.2.key']);
    $this->provider->provision(new GlobalIdentityKeyReference('root', 2));
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        $this->wrapper->wrap($key, $this->workingKey, $this->root);
        $this->fail('Missing root must abort wrapping.');
    } catch (GlobalIdentityKeyException $failure) {
        $wrapFrame = collect($failure->getTrace())->firstWhere('function', 'wrap');
        expect($failure->getMessage())->toBe('Global identity root unavailable.')
            ->and($failure->getPrevious())->toBeNull()
            ->and($wrapFrame['args'][0])->toBeInstanceOf(SensitiveParameterValue::class)
            ->and($failure->getTraceAsString())->not->toContain($key, $this->rootDirectory);
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});
