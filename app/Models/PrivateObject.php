<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $purpose
 * @property string $object_key
 * @property string $backend_identity
 * @property string $state
 * @property string $storage_sha256
 * @property int $stored_bytes
 * @property array{scheme: string, generation: int, key_reference: string, wrapped_dek: string, wrapped_nonce: string} $crypto
 * @property list<string> $replication_targets
 * @property \Carbon\CarbonImmutable|null $verified_at
 */
class PrivateObject extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $hidden = ['crypto'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer', 'stored_bytes' => 'integer',
            'crypto' => 'array', 'replication_targets' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
