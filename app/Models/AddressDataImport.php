<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $country_code
 * @property string $source_name
 * @property string $source_url
 * @property string|null $source_etag
 * @property string|null $source_last_modified
 * @property string|null $source_sha256
 * @property string $status
 * @property int $row_count
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property string|null $error_message
 * @property string|null $license
 * @property string|null $attribution
 */
class AddressDataImport extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'country_code',
        'source_name',
        'source_url',
        'source_etag',
        'source_last_modified',
        'source_sha256',
        'status',
        'row_count',
        'started_at',
        'finished_at',
        'activated_at',
        'error_message',
        'license',
        'attribution',
    ];

    /**
     * @return HasMany<AddressStreet, $this>
     */
    public function streets(): HasMany
    {
        return $this->hasMany(AddressStreet::class, 'import_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }
}
