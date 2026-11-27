<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal Use the registry to authenticate and consume a working key.
 *
 * @property string $purpose
 * @property string $key_id
 * @property int $key_version
 * @property string $algorithm
 * @property string $state
 * @property string $root_id
 * @property int $root_version
 * @property int|null $previous_lookup_version
 * @property bool $lookup_cleanup
 * @property string|null $delivery_block_reason
 * @property int $wrapping_format_version
 * @property string $wrapping_algorithm
 * @property-read string $nonce_hex
 * @property-read string $key_hex
 */
final class GlobalIdentityWorkingKey extends Model
{
    public const CANDIDATE = 'candidate';

    public const WRITE_ACTIVE = 'write-active';

    public const DECRYPT_ONLY = 'decrypt-only';

    public const RETIRED = 'retired';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['wrapping_nonce', 'wrapped_key', 'nonce_hex', 'key_hex'];

    /** @return Builder<self> */
    public static function envelopeQuery(): Builder
    {
        // PostgreSQL PDO otherwise exposes bytea columns as streams.
        return self::query()->selectRaw(
            'id, key_id, key_version, algorithm, state, root_id, root_version, previous_lookup_version, lookup_cleanup, delivery_block_reason, wrapping_format_version, wrapping_algorithm, '.
            "encode(wrapping_nonce, 'hex') AS nonce_hex, encode(wrapped_key, 'hex') AS key_hex",
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'previous_lookup_version' => 'integer',
            'lookup_cleanup' => 'boolean',
            'root_version' => 'integer',
            'wrapping_format_version' => 'integer',
            'activated_at' => 'immutable_datetime',
        ];
    }
}
