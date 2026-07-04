<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $created_by
 * @property string|null $device_label
 * @property string $enrollment_mode
 * @property string $update_channel
 * @property string $release_metadata_url
 * @property array<string, mixed> $provisioning_profile
 * @property string $bootstrap_token
 * @property string $bootstrap_token_lookup_hash
 * @property \Illuminate\Support\Carbon $bootstrap_token_expires_at
 * @property \Illuminate\Support\Carbon|null $exchanged_at
 * @property string|null $exchanged_from_ip
 * @property string|null $exchanged_user_agent
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $status
 */
class AndroidEnrollmentSession extends Model
{
    /** @use HasFactory<\Database\Factories\AndroidEnrollmentSessionFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'created_by',
        'device_label',
        'enrollment_mode',
        'update_channel',
        'release_metadata_url',
        'provisioning_profile',
        'bootstrap_token',
        'bootstrap_token_lookup_hash',
        'bootstrap_token_expires_at',
        'exchanged_at',
        'exchanged_from_ip',
        'exchanged_user_agent',
        'revoked_at',
        'revocation_reason',
        'notes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'tenant_id' => 'integer',
        'provisioning_profile' => 'array',
        'bootstrap_token_expires_at' => 'datetime',
        'exchanged_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @var list<string> */
    protected $hidden = [
        'bootstrap_token',
        'bootstrap_token_lookup_hash',
    ];

    /** @var list<string> */
    protected $appends = ['status'];

    /**
     * @param array{
     *     device_label?: string|null,
     *     enrollment_mode?: string,
     *     update_channel?: string,
     *     provisioning_profile?: array<string, mixed>,
     *     expires_in_minutes?: int,
     *     notes?: string|null
     * } $attributes
     * @return array{model: self, plain: string}
     */
    public static function generate(User $creator, array $attributes = []): array
    {
        $plainToken = Str::random(64);
        $channel = $attributes['update_channel'] ?? 'managed_device';
        $expiresInMinutes = $attributes['expires_in_minutes'] ?? 15;

        $session = self::create([
            'tenant_id' => $creator->tenant_id,
            'created_by' => $creator->id,
            'device_label' => $attributes['device_label'] ?? null,
            'enrollment_mode' => $attributes['enrollment_mode'] ?? 'device_owner',
            'update_channel' => $channel,
            'release_metadata_url' => self::buildReleaseMetadataUrl($channel),
            'provisioning_profile' => $attributes['provisioning_profile'] ?? [],
            'bootstrap_token' => Hash::make($plainToken),
            'bootstrap_token_lookup_hash' => self::buildTokenLookupHash($plainToken),
            'bootstrap_token_expires_at' => now()->addMinutes($expiresInMinutes),
            'notes' => $attributes['notes'] ?? null,
        ]);

        return [
            'model' => $session,
            'plain' => $plainToken,
        ];
    }

    public static function lookupByPlainToken(string $plainToken): ?self
    {
        $lookupHash = self::buildTokenLookupHash($plainToken);

        $session = self::query()
            ->where('bootstrap_token_lookup_hash', $lookupHash)
            ->first();

        if (! $session instanceof self) {
            return null;
        }

        return Hash::check($plainToken, $session->bootstrap_token) ? $session : null;
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        $session = self::lookupByPlainToken($plainToken);

        return $session instanceof self && $session->isPending() ? $session : null;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function revoke(string $reason): void
    {
        $this->update([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);
    }

    public function markAsExchanged(string $ip, string $userAgent): void
    {
        $this->update([
            'exchanged_at' => now(),
            'exchanged_from_ip' => $ip,
            'exchanged_user_agent' => substr($userAgent, 0, 500),
        ]);
    }

    /** @return array<string, mixed> */
    public function provisioningQrPayload(string $plainToken): array
    {
        $packageDownloadUrl = sprintf(
            '%s/android/channels/%s/app.secpal-latest.apk',
            rtrim(self::stringConfig('android.artifact_base_url', 'https://apk.secpal.app'), '/'),
            $this->update_channel,
        );

        return [
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME' => self::stringConfig('android.device_admin_component_name', 'app.secpal/.SecPalDeviceAdminReceiver'),
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION' => $packageDownloadUrl,
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM' => self::stringConfig('android.signing_certificate_checksum', 'm2N7N0F4Q2ZwS0V0bDhlWlU4a1pMRTNwckE3WlJtWm9Kc2J0S2x2dz0='),
            'android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE' => [
                'bootstrap_token' => $plainToken,
                'enrollment_session_id' => $this->id,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function bootstrapConfiguration(): array
    {
        return [
            'enrollment_session_id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant_name' => 'Tenant '.$this->tenant_id,
            'api_base_url' => self::stringConfig('android.api_base_url', 'https://api.secpal.dev/v1'),
            'update_channel' => $this->update_channel,
            'release_metadata_url' => $this->release_metadata_url,
            'provisioning_profile' => $this->provisioning_profile,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return Attribute<string, never> */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->revoked_at !== null) {
                return 'revoked';
            }

            if ($this->exchanged_at !== null) {
                return 'exchanged';
            }

            if ($this->bootstrap_token_expires_at !== null && $this->bootstrap_token_expires_at->isPast()) {
                return 'expired';
            }

            return 'pending';
        });
    }

    private static function buildTokenLookupHash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private static function buildReleaseMetadataUrl(string $channel): string
    {
        $artifactBaseUrl = rtrim(self::stringConfig('android.artifact_base_url', 'https://apk.secpal.app'), '/');

        return sprintf('%s/android/channels/%s/latest.json', $artifactBaseUrl, $channel);
    }

    private static function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}
