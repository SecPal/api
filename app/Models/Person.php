<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Person model with field-level encryption and blind indexes.
 *
 * Encrypted fields (*_enc) use Eloquent encrypted cast.
 * Blind indexes (*_idx) are computed automatically by PersonObserver.
 * Transient properties (email_plain, phone_plain) provide write-only plaintext access.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email_enc BYTEA encrypted email
 * @property string $email_idx BYTEA blind index for email
 * @property string $phone_enc BYTEA encrypted phone
 * @property string $phone_idx BYTEA blind index for phone
 * @property string|null $note_enc TEXT encrypted note
 * @property string|null $note_tsv tsvector for FTS
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-write string|null $email_plain Transient plaintext email
 * @property-write string|null $phone_plain Transient plaintext phone
 */
class Person extends Model
{
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
            'email_enc' => 'encrypted',
            'phone_enc' => 'encrypted',
            'note_enc' => 'encrypted',
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
     * Relation to TenantKey.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<TenantKey, self>
     */
    public function tenantKey(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }
}
