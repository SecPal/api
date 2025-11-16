<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Models;

use App\Casts\EncryptedWithDek;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Secret model with field-level encryption and blind indexes.
 *
 * Encrypted fields (*_enc) use EncryptedWithDek cast and are stored as TEXT.
 * Blind indexes (*_idx) are computed automatically by SecretObserver.
 * Transient properties (*_plain) provide read/write plaintext access.
 *
 * **Accessor Pattern:**
 * Unlike PersonModel which has write-only transients, Secret's *_plain getters
 * fall back to decrypting *_enc fields. This enables reading secrets from the API
 * and tests without exposing encrypted fields. After fresh(), transients are null
 * and decryption happens on each access. For performance-critical code, cache
 * the plaintext value in a local variable.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys table
 * @property string $owner_id UUID foreign key to users table
 * @property string $title_enc Encrypted title (JSON)
 * @property string $title_idx Blind index for title search
 * @property ?string $username_enc Encrypted username (JSON)
 * @property ?string $password_enc Encrypted password (JSON)
 * @property ?string $url_enc Encrypted URL (JSON)
 * @property ?string $notes_enc Encrypted notes (JSON)
 * @property ?array<string> $tags Array of tag strings
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property int $version
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 * @property-write string $title_plain Transient plaintext title
 * @property-write string|null $username_plain Transient plaintext username
 * @property-write string|null $password_plain Transient plaintext password
 * @property-write string|null $url_plain Transient plaintext URL
 * @property-write string|null $notes_plain Transient plaintext notes
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Secret newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Secret newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Secret query()
 * @method static \Illuminate\Database\Eloquent\Builder|Secret owned(string $userId)
 * @method static \Illuminate\Database\Eloquent\Builder|Secret active()
 * @method static \Illuminate\Database\Eloquent\Builder|Secret expired()
 */
class Secret extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'secrets';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'owner_id',
        'title_enc',
        'username_enc',
        'password_enc',
        'url_enc',
        'notes_enc',
        'tags',
        'expires_at',
        'version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * Protects encrypted fields and blind indexes from JSON exposure.
     *
     * @var list<string>
     */
    protected $hidden = [
        'title_enc',
        'title_idx',
        'username_enc',
        'password_enc',
        'url_enc',
        'notes_enc',
        'notes_tsv',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title_enc' => EncryptedWithDek::class,
            'username_enc' => EncryptedWithDek::class,
            'password_enc' => EncryptedWithDek::class,
            'url_enc' => EncryptedWithDek::class,
            'notes_enc' => EncryptedWithDek::class,
            'tags' => 'array',
            'expires_at' => 'datetime',
            'version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Transient plaintext properties (write-only).
     */
    private ?string $titlePlain = null;

    private ?string $usernamePlain = null;

    private ?string $passwordPlain = null;

    private ?string $urlPlain = null;

    private ?string $notesPlain = null;

    /**
     * Set plaintext title (transient).
     */
    public function setTitlePlainAttribute(string $value): void
    {
        $this->titlePlain = $value;
        $this->title_enc = $value; // Trigger encrypted cast
    }

    /**
     * Get plaintext title (transient).
     */
    public function getTitlePlainAttribute(): ?string
    {
        return $this->titlePlain ?? $this->title_enc;
    }

    /**
     * Set plaintext username (transient).
     */
    public function setUsernamePlainAttribute(?string $value): void
    {
        $this->usernamePlain = $value;
        if ($value !== null) {
            $this->username_enc = $value; // Trigger encrypted cast
        }
    }

    /**
     * Get plaintext username (transient).
     */
    public function getUsernamePlainAttribute(): ?string
    {
        return $this->usernamePlain ?? $this->username_enc;
    }

    /**
     * Set plaintext password (transient).
     */
    public function setPasswordPlainAttribute(?string $value): void
    {
        $this->passwordPlain = $value;
        if ($value !== null) {
            $this->password_enc = $value; // Trigger encrypted cast
        }
    }

    /**
     * Get plaintext password (transient).
     */
    public function getPasswordPlainAttribute(): ?string
    {
        return $this->passwordPlain ?? $this->password_enc;
    }

    /**
     * Set plaintext URL (transient).
     */
    public function setUrlPlainAttribute(?string $value): void
    {
        $this->urlPlain = $value;
        if ($value !== null) {
            $this->url_enc = $value; // Trigger encrypted cast
        }
    }

    /**
     * Get plaintext URL (transient).
     */
    public function getUrlPlainAttribute(): ?string
    {
        return $this->urlPlain ?? $this->url_enc;
    }

    /**
     * Set plaintext notes (transient).
     */
    public function setNotesPlainAttribute(?string $value): void
    {
        $this->notesPlain = $value;
        if ($value !== null) {
            $this->notes_enc = $value; // Trigger encrypted cast
        }
    }

    /**
     * Get plaintext notes (transient).
     */
    public function getNotesPlainAttribute(): ?string
    {
        return $this->notesPlain ?? $this->notes_enc;
    }

    /**
     * Relation to TenantKey.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenantKey(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Relation to User (owner).
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Scope to filter by owner.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeOwned($query, User $user)
    {
        return $query->where('owner_id', $user->id);
    }

    /**
     * Scope to filter expired secrets.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope to filter active (not expired) secrets.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
