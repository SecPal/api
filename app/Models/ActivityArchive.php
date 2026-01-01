<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ActivityArchive - GDPR-compliant immutable archive for deleted activity logs.
 *
 * Purpose:
 * - Stores ONLY cryptographic hashes and minimal metadata
 * - Explicitly EXCLUDES all personal data (properties, subject, causer, description)
 * - Maintains hash chain integrity after retention policy deletion
 * - Enables tamper verification even after personal data removal
 *
 * GDPR Compliance (Article 5(1)(e) - Storage Limitation):
 * - Personal data deleted per retention policy
 * - Cryptographic hashes retained for legal verification
 * - Data minimization principle applied
 *
 * BewachV §21 Abs. 4 Compliance:
 * - 8-year retention logs: Archived after retention period ends
 * - Hash chain continuity preserved across deletion boundaries
 *
 * Immutability:
 * - No updated_at timestamp (archive is immutable)
 * - No soft deletes (hard delete only)
 * - No modifications after creation
 *
 * @property string $id - Original Activity Log UUID (preserves chain reference)
 * @property int $tenant_id - Multi-tenant isolation
 * @property string|null $log_name - Log category (security, authentication, etc.)
 * @property \Carbon\Carbon $created_at - Original activity timestamp
 * @property string|null $event_hash - SHA256 hash of archived activity
 * @property string|null $previous_hash - Hash of predecessor (chain link)
 * @property string|null $merkle_root - Merkle tree root (batch verification)
 * @property int|null $merkle_batch_id - Merkle tree batch ID
 *
 * @see ADR-010 Section 5: Retention & Archiving
 * @see Issue #392 PR-8: Create ActivityArchive model & retention commands
 * @see Issue #386 PR-1: Install Spatie Activity Log & extend database schema
 */
class ActivityArchive extends Model
{
    /** @use HasFactory<\Database\Factories\ActivityArchiveFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'activity_log_archive';

    /**
     * The primary key type.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Disable updated_at timestamp (immutable archive).
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',               // Must be explicitly set to original Activity ID
        'tenant_id',
        'log_name',
        'created_at',
        'event_hash',
        'previous_hash',
        'merkle_root',
        'merkle_batch_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'merkle_batch_id' => 'integer',
    ];

    /**
     * Get the tenant that owns the archived activity.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Verify hash chain integrity within archive.
     *
     * Checks if this archived log's previous_hash correctly references
     * another archived or active log's event_hash.
     *
     * @return bool True if chain is valid or this is a genesis log
     */
    public function verifyChain(): bool
    {
        // Genesis log (no predecessor)
        if ($this->previous_hash === null) {
            return true;
        }

        // Check active logs first (might reference non-archived predecessor)
        $previousInActive = Activity::where('tenant_id', $this->tenant_id)
            ->where('event_hash', $this->previous_hash)
            ->whereNull('deleted_at')
            ->exists();

        if ($previousInActive) {
            return true;
        }

        // Check archived logs
        $previousInArchive = self::where('tenant_id', $this->tenant_id)
            ->where('event_hash', $this->previous_hash)
            ->exists();

        return $previousInArchive;
    }

    /**
     * Get the next log in the chain (archived or active).
     */
    public function nextLog(): Activity|self|null
    {
        // Check active logs first
        /** @var Activity|null $activeNext */
        $activeNext = Activity::where('tenant_id', $this->tenant_id)
            ->where('previous_hash', $this->event_hash)
            ->whereNull('deleted_at')
            ->first();

        if ($activeNext !== null) {
            return $activeNext;
        }

        // Check archived logs
        return self::where('tenant_id', $this->tenant_id)
            ->where('previous_hash', $this->event_hash)
            ->first();
    }

    /**
     * Scope query to a specific tenant.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ActivityArchive>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ActivityArchive>
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope query to a specific log category.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ActivityArchive>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ActivityArchive>
     */
    public function scopeOfLog($query, string $logName)
    {
        return $query->where('log_name', $logName);
    }

    /**
     * Scope query to archives older than a given date.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ActivityArchive>  $query
     * @param  \Carbon\Carbon|\DateTimeInterface  $date
     * @return \Illuminate\Database\Eloquent\Builder<ActivityArchive>
     */
    public function scopeOlderThan($query, $date)
    {
        return $query->where('created_at', '<', $date);
    }
}
