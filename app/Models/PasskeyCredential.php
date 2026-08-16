<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Database\Factories\PasskeyCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * @property string $id
 * @property string $user_id
 * @property string $credential_id
 * @property string $label
 * @property array<int, string> $transports
 * @property string|null $authenticator_attachment
 * @property string|null $aaguid
 * @property string $attestation_type
 * @property string $credential_public_key
 * @property string $user_handle
 * @property int $counter
 * @property bool $user_verified
 * @property bool $backup_eligible
 * @property bool $backup_state
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class PasskeyCredential extends Model
{
    /** @use HasFactory<PasskeyCredentialFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'credential_id',
        'label',
        'transports',
        'authenticator_attachment',
        'aaguid',
        'attestation_type',
        'credential_public_key',
        'user_handle',
        'counter',
        'user_verified',
        'backup_eligible',
        'backup_state',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'counter' => 'integer',
            'user_verified' => 'boolean',
            'backup_eligible' => 'boolean',
            'backup_state' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toPublicKeyCredentialSource(): CredentialRecord
    {
        return CredentialRecord::create(
            Base64UrlSafe::decodeNoPadding($this->credential_id),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            is_array($this->transports) ? $this->transports : [],
            $this->attestation_type,
            EmptyTrustPath::create(),
            Uuid::fromString($this->aaguid ?? '00000000-0000-0000-0000-000000000000'),
            Base64UrlSafe::decodeNoPadding($this->credential_public_key),
            Base64UrlSafe::decodeNoPadding($this->user_handle),
            $this->counter,
            backupEligible: $this->backup_eligible,
            backupStatus: $this->backup_state,
            uvInitialized: $this->user_verified,
        );
    }
}
