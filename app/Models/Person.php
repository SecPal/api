<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Casts\TenantEncrypted;
use App\Support\BlindIndex;
use App\Support\KeyStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Person model with encrypted PII and blind indexes.
 *
 * SECURITY NOTES (Option B - Tenant-DEK):
 * - All *_enc fields encrypted with tenant-specific DEK via TenantEncrypted cast (libsodium AEAD)
 * - All *_nonce fields store 12-byte nonces for AEAD encryption (BYTEA)
 * - All *_idx fields are HMAC-SHA256 blind indexes (BYTEA) for equality search
 * - Use transient *_plain attributes to set values (e.g., $person->email_plain = '...')
 * - Never expose *_enc, *_nonce, or *_idx in API responses ($hidden enforced)
 * - tenant_id is REQUIRED for all operations
 *
 * @property string $id UUID
 * @property string $tenant_id UUID (tenant isolation)
 * @property string $email_enc Encrypted email (AEAD ciphertext)
 * @property string $email_nonce Nonce for email encryption (12 bytes)
 * @property string|null $phone_enc Encrypted phone (AEAD ciphertext)
 * @property string|null $phone_nonce Nonce for phone encryption (12 bytes)
 * @property string|null $address_enc Encrypted address (AEAD ciphertext)
 * @property string|null $address_nonce Nonce for address encryption (12 bytes)
 * @property string|null $note_enc Encrypted note (AEAD ciphertext)
 * @property string|null $note_nonce Nonce for note encryption (12 bytes)
 * @property string $email_idx Blind index for email (HMAC-SHA256, BYTEA)
 * @property string|null $phone_idx Blind index for phone
 * @property \Illuminate\Support\Carbon $created_at
 *
 * Transient attributes (not persisted, used for index generation):
 * @property-write string $email_plain Set plaintext email
 * @property-write string|null $phone_plain Set plaintext phone
 * @property-write string|null $address_plain Set plaintext address
 * @property-write string|null $note_plain Set plaintext note
 */
class Person extends Model
{
    use HasUuids;

    protected $table = 'person';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'email_enc',
        'phone_enc',
        'address_enc',
        'note_enc',
    ];

    /**
     * SECURITY: Tenant-DEK encryption (Option B) with libsodium AEAD.
     * Nonces stored in separate *_nonce columns.
     */
    protected $casts = [
        'email_enc' => TenantEncrypted::class,
        'phone_enc' => TenantEncrypted::class,
        'address_enc' => TenantEncrypted::class,
        'note_enc' => TenantEncrypted::class,
        'created_at' => 'datetime',
    ];

    /**
     * SECURITY: Hide encrypted fields, nonces, and blind indexes from API responses.
     */
    protected $hidden = [
        'email_enc',
        'phone_enc',
        'address_enc',
        'note_enc',
        'email_nonce',
        'phone_nonce',
        'address_nonce',
        'note_nonce',
        'email_idx',
        'phone_idx',
    ];

    /**
     * Expose decrypted values via accessors.
     */
    protected $appends = [
        'email',
        'phone',
        'address',
        'note',
    ];

    // Transient attributes for plaintext input (triggers index generation)
    private ?string $emailPlain = null;

    private ?string $phonePlain = null;

    private ?string $addressPlain = null;

    private ?string $notePlain = null;

    /**
     * Boot model and register event listeners.
     */
    protected static function booted(): void
    {
        // Before saving, generate blind indexes for any changed plaintext fields
        static::saving(function (Person $person) {
            if (! $person->tenant_id) {
                throw new \RuntimeException('tenant_id is required for Person model');
            }

            try {
                $keyStore = app(KeyStore::class);
                $idxKey = $keyStore->unwrapIdxKeyForTenant($person->tenant_id);

                // Email index (REQUIRED)
                if ($person->emailPlain !== null) {
                    $person->email_enc = $person->emailPlain;
                    $normalized = BlindIndex::normEmail($person->emailPlain);
                    $person->email_idx = BlindIndex::hmac($normalized, $idxKey);

                    Log::debug('Generated email blind index', [
                        'tenant_id' => $person->tenant_id,
                        'normalized_email' => $normalized,
                    ]);
                }

                // Phone index (optional)
                if ($person->phonePlain !== null) {
                    $person->phone_enc = $person->phonePlain;
                    $normalized = BlindIndex::normPhone($person->phonePlain);
                    $person->phone_idx = BlindIndex::hmac($normalized, $idxKey);

                    Log::debug('Generated phone blind index', [
                        'tenant_id' => $person->tenant_id,
                        'normalized_phone' => $normalized,
                    ]);
                }

                // Address (no index, encryption only)
                if ($person->addressPlain !== null) {
                    $person->address_enc = $person->addressPlain;
                }

                // Note (no index, but tsvector auto-generated by DB trigger)
                if ($person->notePlain !== null) {
                    $person->note_enc = $person->notePlain;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to generate blind indexes', [
                    'tenant_id' => $person->tenant_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Set plaintext email (triggers index generation on save).
     */
    public function setEmailPlainAttribute(?string $value): void
    {
        $this->emailPlain = $value;
    }

    /**
     * Set plaintext phone (triggers index generation on save).
     */
    public function setPhonePlainAttribute(?string $value): void
    {
        $this->phonePlain = $value;
    }

    /**
     * Set plaintext address (encryption only, no index).
     */
    public function setAddressPlainAttribute(?string $value): void
    {
        $this->addressPlain = $value;
    }

    /**
     * Set plaintext note (encryption only, tsvector auto-generated by DB trigger).
     */
    public function setNotePlainAttribute(?string $value): void
    {
        $this->notePlain = $value;
    }

    /**
     * Get decrypted email (via encrypted cast).
     */
    public function getEmailAttribute(): ?string
    {
        return $this->email_enc;
    }

    /**
     * Get decrypted phone.
     */
    public function getPhoneAttribute(): ?string
    {
        return $this->phone_enc;
    }

    /**
     * Get decrypted address.
     */
    public function getAddressAttribute(): ?string
    {
        return $this->address_enc;
    }

    /**
     * Get decrypted note.
     */
    public function getNoteAttribute(): ?string
    {
        return $this->note_enc;
    }

    /**
     * Relationship to tenant keys.
     */
    public function tenantKey(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id', 'tenant_id');
    }

    /**
     * Scope query to specific tenant (SECURITY: always use this).
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
