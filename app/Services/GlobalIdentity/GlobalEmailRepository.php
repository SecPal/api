<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use SensitiveParameter;

final readonly class GlobalEmailRepository
{
    private const NORMALIZATION = GlobalEmailAddress::NORMALIZATION_VERSION;

    private const RESERVATION_LOCK_NAMESPACE = 0x47494552;

    public function __construct(
        private GlobalIdentityWorkingKeyRegistry $keys,
        private GlobalEmailCodec $codec,
    ) {}

    public function create(
        #[SensitiveParameter] User $newUser,
        #[SensitiveParameter] string $email,
        GlobalEmailLookupVersions $versions,
        ?string $reservationId = null,
    ): string {
        if ($newUser->exists || array_key_exists('email', $newUser->getAttributes())) {
            $this->reject('create', 'invalid_user');
        }
        $user = clone $newUser;
        $id = $user->getKey() ?? $user->newUniqueId();
        if (! is_string($id)) {
            $this->reject('create', 'invalid_identifier');
        }
        $userId = $this->uuid($id);
        $reservationId = $reservationId === null ? null : $this->uuid($reservationId);
        $address = $this->address($email, 'create');

        return $this->checked('create', function () use ($versions, $address, $user, $userId, $reservationId): string {
            return $this->write($address, $versions, function (array $indexes, array $reservations, int $reservationVersion) use ($versions, $address, $user, $userId, $reservationId): string {
                $this->requireAvailable($indexes, $reservations, $reservationVersion, $reservationId);
                $user->forceFill([
                    ...$this->identityAttributes($address, $userId, $indexes[$versions->write], $versions->write),
                    'id' => $userId,
                    'email_version' => 1,
                ]);
                if (! $user->save()) {
                    $this->reject('create', 'write_failed');
                }

                $this->storeRotationIndexes($userId, $indexes, $versions->write);
                if ($reservationId !== null) {
                    $this->reservations($reservationId)->delete();
                }

                return $userId;
            }, $reservationId);
        });
    }

    public function findUserId(#[SensitiveParameter] string $email, GlobalEmailLookupVersions $versions): ?string
    {
        $address = $this->address($email, 'lookup');

        // One statement observes both indexed projections at one MVCC snapshot.
        return $this->checked('lookup', function () use ($address, $versions): ?string {
            $ids = $this->matchingUsers($this->lookupIndexes($address, $versions));
            if (count($ids) > 1) {
                $this->reject('lookup', 'conflicting_index');
            }

            return $ids[0] ?? null;
        });
    }

    /** @return array{id: string, email_version: int}|null */
    public function findUserIdentity(#[SensitiveParameter] string $email, GlobalEmailLookupVersions $versions): ?array
    {
        $address = $this->address($email, 'lookup');

        return $this->checked('lookup', function () use ($address, $versions): ?array {
            $matches = DB::query()
                ->fromSub($this->lookupProjection($this->lookupIndexes($address, $versions)), 'matches')
                ->join('users', 'users.id', '=', 'matches.user_id')
                ->limit(2)->get(['users.id', 'users.email_version']);
            if ($matches->count() > 1) {
                $this->reject('lookup', 'conflicting_index');
            }
            $match = $matches->first();
            if ($match === null) {
                return null;
            }
            if (! is_string($match->id) || ! is_int($match->email_version)) {
                $this->reject('lookup', 'invalid_identifier');
            }

            return ['id' => $match->id, 'email_version' => $match->email_version];
        });
    }

    public function reserve(string $operationId, #[SensitiveParameter] string $email, GlobalEmailLookupVersions $versions): void
    {
        $operationId = $this->uuid($operationId);
        $address = $this->address($email, 'reserve');

        $this->checked('reserve', function () use ($operationId, $address, $versions): void {
            $this->write($address, $versions, function (array $indexes, array $reservations, int $reservationVersion) use ($operationId): void {
                $this->requireAvailable($indexes, $reservations, $reservationVersion, $operationId, requireReservation: false);
                foreach ($reservations as $keyVersion => $digest) {
                    DB::table(GlobalEmailSchema::RESERVATIONS)->insertOrIgnore([
                        'operation_id' => $operationId,
                        'normalization_version' => self::NORMALIZATION,
                        'index_key_version' => $keyVersion,
                        'reservation_key_version' => $reservationVersion,
                        'digest' => self::bytea($digest),
                    ]);
                }
                // Concurrent reuse of an operation ID for a different address also fails.
                $this->verifyReservation($operationId, $reservations, $reservationVersion, required: true, complete: true);
            }, $operationId);
        });
    }

    public function release(string $operationId): void
    {
        $operationId = $this->uuid($operationId);
        $this->checked('release', function () use ($operationId): void {
            $this->keys->withReadLease(function () use ($operationId): void {
                $this->lockReservationOperation($operationId);
                $this->reservations($operationId)->delete();
            });
        });
    }

    public function activate(
        string $userId,
        int $expectedVersion,
        string $operationId,
        #[SensitiveParameter] string $email,
        GlobalEmailLookupVersions $versions,
    ): int {
        $userId = $this->uuid($userId);
        $operationId = $this->uuid($operationId);
        $address = $this->address($email, 'activate');

        return $this->checked('activate', function () use ($userId, $expectedVersion, $operationId, $address, $versions): int {
            return $this->write($address, $versions, function (array $indexes, array $reservations, int $reservationVersion) use ($userId, $expectedVersion, $operationId, $address, $versions): int {
                $this->requireAvailable($indexes, $reservations, $reservationVersion, $operationId);
                $attributes = $this->identityAttributes($address, $userId, $indexes[$versions->write], $versions->write);
                $affected = DB::table('users')->where('id', $userId)->where('email_version', $expectedVersion)->update([
                    ...$attributes,
                    'email_version' => $expectedVersion + 1,
                    'email_verified_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($affected !== 1) {
                    $this->reject('activate', 'stale_version');
                }

                $this->storeRotationIndexes($userId, $indexes, $versions->write);
                $this->reservations($operationId)->delete();

                return $expectedVersion + 1;
            }, $operationId);
        });
    }

    /**
     * Recheck the User within the operation protocol's final transaction.
     * A concurrent activation cannot change the version before that transaction commits.
     */
    public function checkEmailVersionForDispatch(string $userId, int $expectedVersion): bool
    {
        if (DB::transactionLevel() === 0) {
            $this->reject('dispatch_version', 'missing_transaction');
        }

        return $this->checked('dispatch_version', fn () => DB::table('users')->where('id', $this->uuid($userId))
            ->sharedLock()->value('email_version') === $expectedVersion);
    }

    public function reencrypt(string $userId): bool
    {
        $userId = $this->uuid($userId);

        return $this->checked('reencrypt', fn (): bool => DB::transaction(function () use ($userId): bool {
            $user = DB::table('users')->where('id', $userId);
            $row = (clone $user)->lockForUpdate()->first(['email_enc']);
            if ($row === null) {
                return false;
            }
            if (! is_string($row->email_enc)) {
                $this->reject('reencrypt', 'invalid_envelope');
            }
            [$address, $previous] = $this->openEmail($userId, $row->email_enc);

            return $this->keys->withActiveKey(
                GlobalIdentityWorkingKeyPurpose::EmailEncryption,
                function (#[SensitiveParameter] string $key, GlobalIdentityWorkingKeyContext $active) use ($user, $userId, $address, $previous): bool {
                    if ($active == $previous) {
                        return false;
                    }
                    $next = $this->codec->encrypt($userId, $address, $active, $key);
                    if ($user->update(['email_enc' => json_encode($next, JSON_THROW_ON_ERROR)]) !== 1) {
                        $this->reject('reencrypt', 'write_failed');
                    }

                    return true;
                },
            );
        }));
    }

    public function completeLookupRotation(GlobalIdentityWorkingKeyContext $target, int $limit): bool
    {
        if ($limit < 1 || $limit > 500) {
            $this->reject('complete_lookup', 'invalid_batch_size');
        }

        return $this->checked('complete_lookup', fn (): bool => $this->keys->completeLookupRotation(
            $target,
            function (int $prior, int $current): void {
                $reservationKey = $this->keys->activeReference(GlobalIdentityWorkingKeyPurpose::UserEmailReservation);
                $this->requireStoredVersions(new GlobalEmailLookupVersions($current, [$prior, $current]), $reservationKey->version);
                $reservations = DB::table(GlobalEmailSchema::RESERVATIONS.' AS r')->where('r.index_key_version', $prior);
                if (DB::table('users')->where('email_idx_key_version', '!=', $current)->exists()
                    || (clone $reservations)->whereNotExists(function (Builder $query) use ($current): void {
                        $query->from(GlobalEmailSchema::RESERVATIONS.' AS next')
                            ->whereColumn('next.operation_id', 'r.operation_id')
                            ->whereColumn('next.normalization_version', 'r.normalization_version')
                            ->whereColumn('next.reservation_key_version', 'r.reservation_key_version')
                            ->where('next.index_key_version', $current);
                    })->exists()) {
                    $this->reject('complete_lookup', 'incomplete_projection');
                }
            },
            function (int $prior, int $current) use ($limit): bool {
                $reservations = DB::table(GlobalEmailSchema::RESERVATIONS)->where('index_key_version', $prior);
                $sidecars = DB::table(GlobalEmailSchema::ROTATION_INDEXES)->where('index_key_version', $prior);
                $users = (clone $sidecars)->orderBy('user_id')->limit($limit)->pluck('user_id')->all();
                $sidecars->whereIn('user_id', $users)->delete();
                $left = $limit - count($users);
                if ($left > 0) {
                    $ids = (clone $reservations)->orderBy('operation_id')->limit($left)->pluck('operation_id')->all();
                    $reservations->whereIn('operation_id', $ids)->delete();
                }

                return ! DB::table(GlobalEmailSchema::ROTATION_INDEXES)->exists()
                    && ! DB::table(GlobalEmailSchema::RESERVATIONS)->where('index_key_version', '!=', $current)->exists();
            },
        ));
    }

    public function rotateIndex(string $userId, bool $promote): bool
    {
        $userId = $this->uuid($userId);

        return $this->checked('rotate_index', fn (): bool => DB::transaction(function () use ($userId, $promote): bool {
            $user = DB::table('users')->where('id', $userId);
            $row = (clone $user)->selectRaw("email_enc, email_normalization_version, email_idx_key_version, encode(email_idx, 'hex') AS idx")
                ->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            if (! is_string($row->email_enc) || ! is_int($row->email_idx_key_version) || ! is_string($row->idx)
                || $row->email_normalization_version !== self::NORMALIZATION) {
                $this->reject('rotate_index', 'invalid_identity');
            }
            [$address] = $this->openEmail($userId, $row->email_enc);

            return $this->keys->withLookupVersions(function (GlobalEmailLookupVersions $versions) use ($userId, $user, $row, $address, $promote): bool {
                if (count($versions->accepted) !== 2) {
                    $this->reject('rotate_index', 'rotation_inactive');
                }
                $current = $row->email_idx_key_version;

                return $this->write($address, $versions, function (array $indexes) use ($versions, $current, $row, $userId, $user, $promote): bool {
                    if (! isset($indexes[$current]) || ! hash_equals(bin2hex($indexes[$current]), $row->idx)
                        || $this->matchingUsers($indexes) !== [$userId]) {
                        $this->reject('rotate_index', 'identity_mismatch');
                    }
                    if ($current === $versions->write) {
                        return false;
                    }
                    $next = $indexes[$versions->write];
                    $exists = $this->verifiedRotationIndex($userId, $versions->write, $next);
                    if ($promote) {
                        if (! $exists) {
                            $this->reject('rotate_index', 'missing_verified_index');
                        }
                        if ($user->update(['email_idx' => self::bytea($next), 'email_idx_key_version' => $versions->write]) !== 1) {
                            $this->reject('rotate_index', 'write_failed');
                        }
                    } elseif ($exists) {
                        return false;
                    }
                    $this->storeRotationIndexes($userId, $indexes, $promote ? $versions->write : $current);

                    return true;
                }, null);
            });
        }));
    }

    private function verifiedRotationIndex(string $userId, int $version, #[SensitiveParameter] string $digest): bool
    {
        $stored = DB::table(GlobalEmailSchema::ROTATION_INDEXES)
            ->where('user_id', $userId)->where('normalization_version', self::NORMALIZATION)
            ->where('index_key_version', $version)->selectRaw("encode(email_idx, 'hex') AS idx")->value('idx');
        if ($stored === null) {
            return false;
        }
        if (! is_string($stored) || ! hash_equals(bin2hex($digest), $stored)) {
            $this->reject('rotate_index', 'sidecar_mismatch');
        }

        return true;
    }

    /**
     * The caller authorizes display or revalidates the claimed mail operation.
     * Mark the callback parameter sensitive and apply the protocol's final fence
     * before transport.
     *
     * @template T
     *
     * @param  Closure(string): T  $consume
     * @return T
     */
    public function withEmail(string $userId, int $expectedVersion, bool $forTransport, Closure $consume): mixed
    {
        $userId = $this->uuid($userId);
        $json = $this->checked('read', fn () => DB::table('users')->where('id', $userId)
            ->where('email_version', $expectedVersion)->value('email_enc'));
        if (! is_string($json)) {
            $this->reject('read', 'stale_version');
        }
        [$address] = $this->openEmail($userId, $json);

        $email = $forTransport ? $address->transport() : $address->original();
        try {
            return $consume($email);
        } finally {
            sodium_memzero($email);
        }
    }

    /** @return array{GlobalEmailAddress, GlobalIdentityWorkingKeyContext} */
    private function openEmail(string $userId, #[SensitiveParameter] string $json): array
    {
        try {
            $envelope = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($envelope)) {
                $this->reject('read', 'malformed_envelope');
            }
            $context = $this->codec->reference($envelope);
            $address = $this->keys->withKey(
                $context,
                fn (#[SensitiveParameter] string $key): GlobalEmailAddress => $this->codec->decrypt($userId, $envelope, $key),
            );
        } catch (GlobalIdentityKeyException|JsonException|QueryException) {
            $this->reject('read', 'invalid_envelope');
        }

        return [$address, $context];
    }

    /**
     * @template T
     *
     * @param  Closure(array<int, string>, array<int, string>, int): T  $use
     * @return T
     */
    private function write(
        #[SensitiveParameter] GlobalEmailAddress $address,
        GlobalEmailLookupVersions $versions,
        Closure $use,
        ?string $operationId,
    ): mixed {
        // The registry lease keeps activation off this transaction's write path.
        return $this->keys->withActiveKey(
            GlobalIdentityWorkingKeyPurpose::UserEmailLookup,
            function (#[SensitiveParameter] string $writeKey, GlobalIdentityWorkingKeyContext $active) use ($address, $versions, $use, $operationId): mixed {
                if ($active->reference->version !== $versions->write) {
                    $this->reject('write', 'stale_write_version');
                }
                $cleanup = $this->keys->lookupCleanupVersion();
                if ($cleanup !== null && $versions->accepted !== [$versions->write]) {
                    $this->reject('write', 'stale_write_version');
                }
                if ($operationId !== null) {
                    $this->lockReservationOperation($operationId);
                }
                $indexes = $this->lookupIndexes($address, $versions, $writeKey);

                return $this->keys->withActiveKey(
                    GlobalIdentityWorkingKeyPurpose::UserEmailReservation,
                    function (#[SensitiveParameter] string $reservationKey, GlobalIdentityWorkingKeyContext $reservation) use ($address, $versions, $indexes, $use, $cleanup, $operationId): mixed {
                        $version = $reservation->reference->version;
                        $this->requireStoredVersions($versions, $version, $cleanup);
                        $reservations = [];
                        $locks = [];
                        foreach ($indexes as $keyVersion => $index) {
                            $reservations[$keyVersion] = hash_hmac('sha256', GlobalIdentityCanonicalEncoding::encode(
                                GlobalIdentityWorkingKeyPurpose::UserEmailReservation->value,
                                (string) self::NORMALIZATION,
                                (string) $keyVersion,
                                $address->normalized(),
                            ), $reservationKey, true);
                            $hash = hash('sha256', GlobalIdentityCanonicalEncoding::encode(
                                GlobalIdentityWorkingKeyPurpose::UserEmailLookup->value,
                                (string) self::NORMALIZATION,
                                (string) $keyVersion,
                                $index,
                            ), true);
                            // The single-bigint lock namespace is distinct from the registry's two-int locks.
                            $lock = unpack('Jlock', $hash)['lock'] ?? null;
                            if (! is_int($lock)) {
                                $this->reject('write', 'invalid_lock');
                            }
                            $locks[] = $lock;
                        }
                        sort($locks, SORT_REGULAR);
                        foreach (array_unique($locks) as $lock) {
                            DB::select('SELECT pg_advisory_xact_lock(CAST(? AS bigint))', [$lock]);
                        }
                        if ($cleanup !== null && $operationId !== null) {
                            $claim = $this->reservations($operationId);
                            if ((clone $claim)->where('index_key_version', $versions->write)->exists()) {
                                $claim->where('index_key_version', $cleanup)->delete();
                            }
                        }

                        return $use($indexes, $reservations, $version);
                    },
                );
            },
        );
    }

    private function lockReservationOperation(string $operationId): void
    {
        $digest = hash('sha256', $operationId, true);
        $lock = unpack('Nlock', $digest)['lock'] ?? null;
        if (! is_int($lock)) {
            $this->reject('reserve', 'invalid_lock');
        }
        // PostgreSQL's two-int namespace requires a signed 32-bit argument.
        $signedLock = $lock > 0x7FFFFFFF ? $lock - 0x100000000 : $lock;
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::RESERVATION_LOCK_NAMESPACE, $signedLock]);
    }

    private function requireStoredVersions(GlobalEmailLookupVersions $versions, int $reservationVersion, ?int $cleanup = null): void
    {
        $accepted = array_map(static fn (int $version): array => [self::NORMALIZATION, $version], $versions->accepted);
        $persisted = $cleanup === null ? $accepted : [...$accepted, [self::NORMALIZATION, $cleanup]];
        $reservationTuples = array_map(static fn (array $pair): array => [...$pair, $reservationVersion], $persisted);

        if (! $this->hasOnlyVersionTuples('users', ['email_normalization_version', 'email_idx_key_version'], $accepted)
            || ! $this->hasOnlyVersionTuples(GlobalEmailSchema::ROTATION_INDEXES, ['normalization_version', 'index_key_version'], $persisted)
            || ! $this->hasOnlyVersionTuples(GlobalEmailSchema::RESERVATIONS, ['normalization_version', 'index_key_version', 'reservation_key_version'], $reservationTuples)) {
            $this->reject('write', 'unaccepted_stored_version');
        }
    }

    /**
     * Seek to the first row after each accepted tuple using the unique index
     * prefix from GlobalEmailSchema. Each probe skips all rows for that tuple.
     *
     * @param  non-empty-list<string>  $columns
     * @param  non-empty-list<list<int>>  $accepted
     */
    private function hasOnlyVersionTuples(string $table, array $columns, array $accepted): bool
    {
        $ordered = DB::table($table);
        foreach ($columns as $column) {
            $ordered->orderBy($column);
        }

        $row = (clone $ordered)->first($columns);
        while ($row !== null) {
            $tuple = array_values(get_object_vars($row));
            if (! in_array($tuple, $accepted, true)) {
                return false;
            }
            $row = (clone $ordered)->whereRowValues($columns, '>', $tuple)->first($columns);
        }

        return true;
    }

    /** @return array<int, string> */
    private function lookupIndexes(
        #[SensitiveParameter] GlobalEmailAddress $address,
        GlobalEmailLookupVersions $versions,
        #[SensitiveParameter] ?string $writeKey = null,
    ): array {
        $indexes = [];
        foreach ($versions->accepted as $keyVersion) {
            $indexes[$keyVersion] = $writeKey !== null && $keyVersion === $versions->write
                ? $this->codec->lookupIndex($address, $writeKey)
                : $this->keys->withPurposeVersion(
                    GlobalIdentityWorkingKeyPurpose::UserEmailLookup,
                    $keyVersion,
                    fn (#[SensitiveParameter] string $key): string => $this->codec->lookupIndex($address, $key),
                );
        }

        return $indexes;
    }

    /**
     * @param  array<int, string>  $indexes
     * @return list<string>
     */
    private function matchingUsers(#[SensitiveParameter] array $indexes): array
    {
        $matches = [];
        foreach ($this->lookupProjection($indexes)->limit(2)->pluck('user_id') as $id) {
            if (! is_string($id)) {
                $this->reject('lookup', 'invalid_identifier');
            }
            $matches[] = $id;
        }

        return $matches;
    }

    /** @param array<int, string> $indexes */
    private function lookupProjection(#[SensitiveParameter] array $indexes): Builder
    {
        $users = DB::table('users')->selectRaw('id AS user_id');
        $rotation = DB::table(GlobalEmailSchema::ROTATION_INDEXES)->select('user_id');
        foreach ($indexes as $version => $index) {
            $users->orWhere(fn (Builder $query) => $query->where([
                'email_normalization_version' => self::NORMALIZATION,
                'email_idx_key_version' => $version,
                'email_idx' => self::bytea($index),
            ]));
            $rotation->orWhere(fn (Builder $query) => $query->where([
                'normalization_version' => self::NORMALIZATION,
                'index_key_version' => $version,
                'email_idx' => self::bytea($index),
            ]));
        }

        return $users->union($rotation);
    }

    /**
     * @param  array<int, string>  $indexes
     * @param  array<int, string>  $reservations
     */
    private function requireAvailable(
        #[SensitiveParameter] array $indexes,
        #[SensitiveParameter] array $reservations,
        int $reservationVersion,
        ?string $operationId,
        bool $requireReservation = true,
    ): void {
        if ($this->matchingUsers($indexes) !== []) {
            $this->conflict();
        }
        $claims = DB::table(GlobalEmailSchema::RESERVATIONS);
        foreach ($reservations as $pairVersion => $digest) {
            $claims->orWhere(fn (Builder $query) => $query->where([
                'normalization_version' => self::NORMALIZATION,
                'index_key_version' => $pairVersion,
                'reservation_key_version' => $reservationVersion,
                'digest' => self::bytea($digest),
            ]));
        }
        $ownerIds = $claims->distinct()->pluck('operation_id')->all();
        if ($ownerIds !== [] && $ownerIds !== [$operationId]) {
            $this->conflict();
        }
        if ($operationId !== null) {
            $this->verifyReservation($operationId, $reservations, $reservationVersion, $requireReservation);
        }
    }

    /** @param array<int, string> $digests */
    private function verifyReservation(
        string $operationId,
        #[SensitiveParameter] array $digests,
        int $version,
        bool $required,
        bool $complete = false,
    ): void {
        $rows = $this->reservations($operationId)->selectRaw("normalization_version, index_key_version, reservation_key_version, encode(digest, 'hex') AS digest_hex")
            ->lockForUpdate()->get();
        if ($rows->isEmpty() && ! $required) {
            return;
        }
        if ($rows->isEmpty() || $rows->count() > count($digests) || ($complete && $rows->count() !== count($digests))) {
            $this->conflict();
        }
        foreach ($rows as $row) {
            $indexKeyVersion = $row->index_key_version;
            $stored = $row->digest_hex;
            $digest = is_int($indexKeyVersion) ? ($digests[$indexKeyVersion] ?? null) : null;
            if ($digest === null || $row->normalization_version !== self::NORMALIZATION
                || $row->reservation_key_version !== $version || ! is_string($stored) || ! hash_equals(bin2hex($digest), $stored)) {
                $this->conflict();
            }
        }
    }

    private function reservations(string $operationId): Builder
    {
        return DB::table(GlobalEmailSchema::RESERVATIONS)->where('operation_id', $this->uuid($operationId));
    }

    /** @param array<int, string> $indexes */
    private function storeRotationIndexes(string $userId, #[SensitiveParameter] array $indexes, int $writeVersion): void
    {
        $table = DB::table(GlobalEmailSchema::ROTATION_INDEXES);
        $table->where('user_id', $userId)->delete();
        foreach ($indexes as $version => $index) {
            if ($version !== $writeVersion) {
                DB::table(GlobalEmailSchema::ROTATION_INDEXES)->insert([
                    'user_id' => $userId,
                    'normalization_version' => self::NORMALIZATION,
                    'index_key_version' => $version,
                    'email_idx' => self::bytea($index),
                ]);
            }
        }
    }

    /** @return array{email_enc:string,email_idx:string,email_normalization_version:int,email_idx_key_version:int} */
    private function identityAttributes(
        #[SensitiveParameter] GlobalEmailAddress $address,
        string $userId,
        #[SensitiveParameter] string $index,
        int $version,
    ): array {
        $envelope = $this->keys->withActiveKey(
            GlobalIdentityWorkingKeyPurpose::EmailEncryption,
            fn (#[SensitiveParameter] string $key, GlobalIdentityWorkingKeyContext $context): array => $this->codec->encrypt(
                $userId, $address, $context, $key,
            ),
        );

        return [
            'email_enc' => json_encode($envelope, JSON_THROW_ON_ERROR),
            'email_idx' => self::bytea($index),
            'email_normalization_version' => self::NORMALIZATION,
            'email_idx_key_version' => $version,
        ];
    }

    private static function bytea(#[SensitiveParameter] string $value): string
    {
        // PostgreSQL's text input notation binds bytes without PDO binary transport issues.
        return '\x'.bin2hex($value);
    }

    private function uuid(string $id): string
    {
        if (! Str::isUuid($id)) {
            $this->reject('resolve', 'invalid_identifier');
        }

        return strtolower($id);
    }

    private function address(#[SensitiveParameter] string $email, string $operation): GlobalEmailAddress
    {
        try {
            return GlobalEmailAddress::parse($email);
        } catch (GlobalIdentityKeyException) {
            $this->reject($operation, 'invalid_address');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $perform
     * @return T
     */
    private function checked(string $operation, Closure $perform): mixed
    {
        try {
            return $perform();
        } catch (UniqueConstraintViolationException) {
            $this->conflict();
        } catch (QueryException|JsonException) {
            $this->reject($operation, 'persistence_failed');
        }
    }

    private function conflict(): never
    {
        Log::warning('Global email rejected.', ['operation' => 'write', 'reason' => 'conflict']);

        throw new GlobalIdentityKeyException('Global email address unavailable.');
    }

    private function reject(string $operation, string $reason): never
    {
        Log::warning('Global email rejected.', ['operation' => $operation, 'reason' => $reason]);

        throw new GlobalIdentityKeyException('Global email operation unavailable.');
    }
}
