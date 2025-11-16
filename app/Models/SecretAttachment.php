<?php

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Casts\EncryptedWithDek;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Secret attachment model with encrypted filename.
 *
 * Encrypted fields (*_enc) use EncryptedWithDek cast and are stored as TEXT.
 * Transient properties (*_plain) provide plaintext access with automatic encryption.
 *
 * @property string $id UUID primary key
 * @property string $secret_id Foreign key to secrets table
 * @property string $filename_enc Encrypted original filename (JSON)
 * @property int $file_size Original file size in bytes
 * @property string $mime_type MIME type (e.g., application/pdf)
 * @property string $storage_path Path to encrypted blob
 * @property ?string $checksum_sha256 SHA-256 checksum of original file
 * @property string $uploaded_by UUID foreign key to users table
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-write string $filename_plain Transient plaintext filename
 * @property-read string $download_url Download URL accessor
 * @property-read Secret $secret Relationship to secret
 * @property-read User $uploader Relationship to uploader
 *
 * @method static \Illuminate\Database\Eloquent\Builder|SecretAttachment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SecretAttachment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SecretAttachment query()
 */
class SecretAttachment extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory, HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'secret_attachments';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'secret_id',
        'tenant_id', // Required for EncryptedWithDek cast
        'filename_enc',
        'file_size',
        'mime_type',
        'storage_path',
        'checksum_sha256',
        'uploaded_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * Protects encrypted fields and storage path from JSON exposure.
     *
     * @var list<string>
     */
    protected $hidden = [
        'filename_enc',
        'storage_path',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filename_enc' => EncryptedWithDek::class,
            'file_size' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Transient plaintext filename (write-only).
     */
    private ?string $filenamePlain = null;

    /**
     * Set plaintext filename (transient).
     */
    public function setFilenamePlainAttribute(string $value): void
    {
        $this->filenamePlain = $value;
        $this->filename_enc = $value; // Trigger encrypted cast
    }

    /**
     * Get plaintext filename (read accessor).
     *
     * Falls back to decrypting filename_enc if transient is null.
     */
    public function getFilenamePlainAttribute(): ?string
    {
        return $this->filenamePlain ?? $this->filename_enc;
    }

    /**
     * Get download URL for this attachment.
     */
    public function getDownloadUrlAttribute(): string
    {
        return url("/api/v1/attachments/{$this->id}/download");
    }

    /**
     * Get the secret that owns this attachment.
     *
     * @return BelongsTo<Secret, $this>
     */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(Secret::class);
    }

    /**
     * Get the user who uploaded this attachment.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
