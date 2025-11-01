<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-specific encryption keys (envelope encryption).
 *
 * @property string $tenant_id UUID (primary key)
 * @property string $dek_wrapped Encrypted DEK (binary)
 * @property string $dek_nonce Nonce for DEK encryption (binary)
 * @property string $idx_wrapped Encrypted index key (binary)
 * @property string $idx_nonce Nonce for index key encryption (binary)
 * @property int $key_version Key version for rotation tracking
 * @property \Illuminate\Support\Carbon $created_at
 */
class TenantKey extends Model
{
    protected $table = 'tenant_keys';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'dek_wrapped',
        'dek_nonce',
        'idx_wrapped',
        'idx_nonce',
        'key_version',
    ];

    protected $casts = [
        'key_version' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * SECURITY: Hide all wrapped keys from API responses.
     */
    protected $hidden = [
        'dek_wrapped',
        'dek_nonce',
        'idx_wrapped',
        'idx_nonce',
    ];
}
