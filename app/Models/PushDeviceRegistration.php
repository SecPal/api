<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $user_id
 * @property string $installation_id
 * @property string $platform
 * @property string $provider
 * @property string $device_name
 * @property string $push_token_enc
 * @property string $token_last_eight
 * @property string $last_lifecycle_event
 * @property string $package_name
 * @property string|null $package_version_name
 * @property int|null $package_version_code
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $android_version
 * @property int|null $sdk_int
 * @property string $bootstrap_version
 * @property int $schema_version
 * @property int $push_metadata_revision
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-write string|null $push_token_plain
 */
class PushDeviceRegistration extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'installation_id',
        'platform',
        'provider',
        'device_name',
        'push_token_plain',
        'push_token_enc',
        'token_last_eight',
        'last_lifecycle_event',
        'package_name',
        'package_version_name',
        'package_version_code',
        'manufacturer',
        'model',
        'android_version',
        'sdk_int',
        'bootstrap_version',
        'schema_version',
        'push_metadata_revision',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'push_token_enc',
    ];

    private ?string $pushTokenPlain = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'push_token_enc' => \App\Casts\EncryptedWithDek::class,
            'package_version_code' => 'integer',
            'sdk_int' => 'integer',
            'schema_version' => 'integer',
            'push_metadata_revision' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function setPushTokenPlainAttribute(?string $value): void
    {
        $this->pushTokenPlain = $value;

        if ($value !== null) {
            $this->push_token_enc = $value;
            $this->token_last_eight = substr($value, -8);
        }
    }

    public function getPushTokenPlainAttribute(): ?string
    {
        return $this->pushTokenPlain;
    }

    /**
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'installation_id' => $this->installation_id,
            'platform' => $this->platform,
            'provider' => $this->provider,
            'device_name' => $this->device_name,
            'token_last_eight' => $this->token_last_eight,
            'last_lifecycle_event' => $this->last_lifecycle_event,
            'app' => [
                'package_name' => $this->package_name,
                'package_version_name' => $this->package_version_name,
                'package_version_code' => $this->package_version_code,
            ],
            'device' => [
                'manufacturer' => $this->manufacturer,
                'model' => $this->model,
                'android_version' => $this->android_version,
                'sdk_int' => $this->sdk_int,
            ],
            'runtime' => [
                'bootstrap_version' => $this->bootstrap_version,
                'schema_version' => $this->schema_version,
                'push_metadata_revision' => $this->push_metadata_revision,
            ],
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
