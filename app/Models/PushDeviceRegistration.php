<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Support\BootstrapContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

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
 * @property string|null $package_name
 * @property string|null $package_version_name
 * @property int|null $package_version_code
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $android_version
 * @property int|null $sdk_int
 * @property string|null $browser_name
 * @property string|null $browser_version
 * @property string|null $service_worker_scope
 * @property string|null $subscription_endpoint_origin
 * @property string|null $subscription_p256dh_enc
 * @property string|null $subscription_auth_enc
 * @property \Illuminate\Support\Carbon|\Carbon\CarbonImmutable|null $subscription_expires_at
 * @property-write string|null $subscription_p256dh_plain
 * @property-write string|null $subscription_auth_plain
 * @property string $bootstrap_version
 * @property int $schema_version
 * @property int $push_metadata_revision
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-write string|null $push_token_plain
 * @property-read string|null $push_token_dec
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
        'token_last_eight',
        'last_lifecycle_event',
        'package_name',
        'package_version_name',
        'package_version_code',
        'manufacturer',
        'model',
        'android_version',
        'sdk_int',
        'browser_name',
        'browser_version',
        'service_worker_scope',
        'subscription_endpoint_origin',
        'subscription_p256dh_plain',
        'subscription_auth_plain',
        'subscription_expires_at',
        'bootstrap_version',
        'schema_version',
        'push_metadata_revision',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'push_token_enc',
        'subscription_p256dh_enc',
        'subscription_auth_enc',
    ];

    private ?string $pushTokenPlain = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'push_token_enc' => \App\Casts\EncryptedWithDek::class,
            'subscription_p256dh_enc' => \App\Casts\EncryptedWithDek::class,
            'subscription_auth_enc' => \App\Casts\EncryptedWithDek::class,
            'package_version_code' => 'integer',
            'sdk_int' => 'integer',
            'schema_version' => 'integer',
            'push_metadata_revision' => 'integer',
            'subscription_expires_at' => 'datetime',
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

    public function getPushTokenDecAttribute(): ?string
    {
        $token = $this->getAttributeValue('push_token_enc');

        return (is_string($token) && trim($token) !== '') ? $token : null;
    }

    public function deliveryToken(): ?string
    {
        return $this->push_token_dec;
    }

    public function setSubscriptionP256dhPlainAttribute(?string $value): void
    {
        if ($value !== null) {
            $this->subscription_p256dh_enc = $value;
        }
    }

    public function setSubscriptionAuthPlainAttribute(?string $value): void
    {
        if ($value !== null) {
            $this->subscription_auth_enc = $value;
        }
    }

    public function notificationChannel(): string
    {
        return match (true) {
            $this->platform === BootstrapContract::CLIENT_PLATFORM_ANDROID
                && $this->provider === BootstrapContract::ANDROID_PUSH_PROVIDER => BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM,
            $this->platform === BootstrapContract::CLIENT_PLATFORM_BROWSER
                && $this->provider === BootstrapContract::WEB_PUSH_PROVIDER => BootstrapContract::NOTIFICATION_CHANNEL_WEB_PUSH,
            default => throw new RuntimeException(sprintf(
                'Unsupported notification installation mapping for platform "%s" and provider "%s".',
                $this->platform,
                $this->provider,
            )),
        };
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
        $channel = $this->notificationChannel();

        return [
            'installation_id' => $this->installation_id,
            'channel' => $channel,
            'installation_name' => $this->device_name,
            'credential_reference' => $this->token_last_eight,
            'last_lifecycle_event' => $this->last_lifecycle_event,
            'registration' => $channel === BootstrapContract::NOTIFICATION_CHANNEL_WEB_PUSH
                ? [
                    'browser' => [
                        'browser_name' => $this->browser_name,
                        'browser_version' => $this->browser_version,
                        'service_worker_scope' => $this->service_worker_scope,
                    ],
                    'subscription_endpoint_origin' => $this->subscription_endpoint_origin,
                    'subscription_expires_at' => $this->subscription_expires_at !== null
                        ? $this->isoUtc($this->subscription_expires_at)
                        : null,
                ]
                : [
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
                ],
            'runtime' => [
                'bootstrap_version' => $this->bootstrap_version,
                'schema_version' => $this->schema_version,
                'metadata_revision' => $this->push_metadata_revision,
            ],
            'created_at' => $this->isoUtc($this->created_at),
            'updated_at' => $this->isoUtc($this->updated_at),
        ];
    }

    private function isoUtc(\DateTimeInterface $timestamp): string
    {
        return \Illuminate\Support\Carbon::instance($timestamp)->utc()->format('Y-m-d\\TH:i:s\\Z');
    }
}
