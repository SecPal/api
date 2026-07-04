<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Person model with field-level encryption and blind indexes.
 *
 * Encrypted fields (*_enc) use Eloquent encrypted cast and are stored as TEXT.
 * Blind indexes (*_idx) are computed automatically by PersonObserver and are stored as VARCHAR.
 * Transient properties (email_plain, phone_plain, note_plain) provide write-only plaintext access.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email_enc TEXT encrypted email
 * @property string $email_idx VARCHAR blind index for email
 * @property string $phone_enc TEXT encrypted phone
 * @property string $phone_idx VARCHAR blind index for phone
 * @property string|null $note_enc TEXT encrypted note
 * @property string|null $note_tsv tsvector for FTS
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-write string|null $email_plain Transient plaintext email
 * @property-write string|null $phone_plain Transient plaintext phone
 * @property-write string|null $note_plain Transient plaintext note
 *
 * @method static \Database\Factories\PersonFactory factory($count = null, $state = [])
 */
class Person extends Model
{
    /** @use HasFactory<\Database\Factories\PersonFactory> */
    use EnforcesTenantRouteBinding, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'person';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'email_enc',
        'phone_enc',
        'note_enc',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * Protects encrypted fields and blind indexes from JSON exposure.
     *
     * @var list<string>
     */
    protected $hidden = [
        'email_enc',
        'email_idx',
        'phone_enc',
        'phone_idx',
        'note_enc',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_enc' => \App\Casts\EncryptedWithDek::class,
            'phone_enc' => \App\Casts\EncryptedWithDek::class,
            'note_enc' => \App\Casts\EncryptedWithDek::class,
            // email_idx and phone_idx are stored as base64 strings directly (no cast needed)
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Transient plaintext email for writing.
     *
     * This property is used by PersonObserver to compute the blind index.
     * It is never stored in the database or exposed in JSON.
     */
    private ?string $emailPlain = null;

    /**
     * Transient plaintext phone for writing.
     *
     * This property is used by PersonObserver to compute the blind index.
     * It is never stored in the database or exposed in JSON.
     */
    private ?string $phonePlain = null;

    /**
     * Transient plaintext note for writing.
     *
     * This property keeps the external API on *_plain fields while the model
     * persists the encrypted note via the casted note_enc column.
     */
    private ?string $notePlain = null;

    /**
     * Set plaintext email (transient).
     */
    public function setEmailPlainAttribute(?string $value): void
    {
        $this->emailPlain = $value;
        if ($value !== null) {
            $this->email_enc = $value; // Trigger encrypted cast
        }
    }

    /**
     * Get plaintext email (transient).
     */
    public function getEmailPlainAttribute(): ?string
    {
        return $this->emailPlain;
    }

    /**
     * Set plaintext phone (transient).
     */
    public function setPhonePlainAttribute(?string $value): void
    {
        $this->phonePlain = $value;
        if ($value !== null) {
            $this->phone_enc = $value; // Trigger encrypted cast
        }
    }

    /**
     * Get plaintext phone (transient).
     */
    public function getPhonePlainAttribute(): ?string
    {
        return $this->phonePlain;
    }

    /**
     * Set plaintext note (transient).
     */
    public function setNotePlainAttribute(?string $value): void
    {
        $this->notePlain = $value;
        $this->note_enc = $value;
    }

    /**
     * Get plaintext note (transient).
     */
    public function getNotePlainAttribute(): ?string
    {
        return $this->notePlain;
    }

    /**
     * Relation to TenantKey.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<TenantKey, self>
     */
    public function tenantKey(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }
}
