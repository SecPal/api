<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Custom Activity model extending Spatie with SecPal forensic extensions.
 *
 * Features:
 * - Automatic tenant/org scope injection
 * - Hash chain building (sequential tamper detection)
 * - Merkle tree batching (hierarchical verification)
 * - OpenTimestamp integration (blockchain anchoring)
 * - 3-tier security levels (BewachV § 21 Abs. 4 retention)
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $organizational_unit_id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $causer_type
 * @property string|null $causer_id
 * @property array<string, mixed>|null $properties
 * @property string|null $batch_uuid
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $previous_hash
 * @property string $event_hash
 * @property string|null $merkle_root
 * @property string|null $merkle_batch_id
 * @property array<string, mixed>|null $merkle_proof
 * @property string|null $ots_proof
 * @property \Carbon\Carbon|null $ots_submitted_at
 * @property \Carbon\Carbon|null $ots_confirmed_at
 * @property bool $is_orphaned_genesis
 * @property string|null $orphaned_reason
 * @property \Carbon\Carbon|null $orphaned_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @see ADR-010 Activity Logging & Audit Trail Strategy
 * @see Issue #387 PR-2: Implement custom Activity model with hash chain
 */
class Activity extends SpatieActivity
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'activity_log';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organizational_unit_id',
        'log_name',
        'description',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'batch_uuid',
        'ip_address',
        'user_agent',
        'previous_hash',
        'event_hash',
        'merkle_root',
        'merkle_batch_id',
        'merkle_proof',
        'ots_proof',
        'ots_submitted_at',
        'ots_confirmed_at',
        'is_orphaned_genesis',
        'orphaned_reason',
        'orphaned_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'properties' => 'array',
        'merkle_proof' => 'array',
        'ots_submitted_at' => 'datetime',
        'ots_confirmed_at' => 'datetime',
        'is_orphaned_genesis' => 'boolean',
        'orphaned_at' => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'causer_id' => 'string',
        'subject_id' => 'string',
    ];

    /**
     * 3-Tier Security Levels (BewachV § 21 Abs. 4 compliance).
     *
     * Level 1: Basic (3 years retention)
     * Level 2: Enhanced (5 years retention)
     * Level 3: Maximum (7 years retention)
     *
     * @var array<string, int>
     */
    protected static array $securityLevels = [
        // Level 1: Standard Operations (3 years)
        'default' => 1,
        'employee_changes' => 1,
        'shift_management' => 1,

        // Level 2: Security-Critical (5 years)
        'security' => 2,
        'authentication' => 2,
        'rbac_changes' => 2,
        'scope_changes' => 2,
        'customer_changes' => 2,
        'site_management' => 2,

        // Level 3: Legal-Critical (7 years)
        'hr_access' => 3,
        'contract_change' => 3,
        'works_council_access' => 3,
        'guard_book_event' => 3,
        'sensitive_access' => 3,

        // Deprecated (kept for backward compatibility)
        'emergency_access' => 3, // Use 'hr_access' or 'sensitive_access' instead
    ];

    /**
     * Bootstrap the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Activity $activity) {
            // Priority 1: Try to get tenant_id from subject model (for auto-logging via LogsActivity trait)
            if ($activity->subject_type && $activity->subject_id) {
                /** @var class-string<\Illuminate\Database\Eloquent\Model> $subjectType */
                $subjectType = $activity->subject_type;
                if (class_exists($subjectType)) {
                    // Use withTrashed() to find soft-deleted models (e.g., during 'deleted' event)
                    $subjectModel = method_exists($subjectType, 'withTrashed')
                        ? $subjectType::withTrashed()->find($activity->subject_id) /** @phpstan-ignore method.nonObject */
                        : $subjectType::find($activity->subject_id);

                    if ($subjectModel instanceof \Illuminate\Database\Eloquent\Model) {
                        /** @var mixed $subjectTenantId */
                        $subjectTenantId = $subjectModel->getAttribute('tenant_id');
                        if (is_int($subjectTenantId)) {
                            $activity->tenant_id = $subjectTenantId;
                        }
                    }
                }
            }

            // Priority 2: Fall back to authenticated user if tenant_id still not set
            if (! $activity->tenant_id && auth()->check() && auth()->user() !== null) {
                /** @var User $user */
                $user = auth()->user();
                $activity->tenant_id = $user->tenant_id;
            }

            // Auto-inject organizational_unit_id from request context
            if (! $activity->organizational_unit_id && request()->has('organizational_unit_id')) {
                /** @var mixed $orgUnitId */
                $orgUnitId = request()->input('organizational_unit_id');
                if (is_string($orgUnitId)) {
                    $activity->organizational_unit_id = $orgUnitId;
                }
            }

            // Capture request metadata
            if (! $activity->ip_address && request()->ip()) {
                $activity->ip_address = request()->ip();
            }

            if (! $activity->user_agent && request()->userAgent()) {
                $activity->user_agent = request()->userAgent();
            }

            // Build hash chain
            $activity->buildHashChain();
        });
    }

    /**
     * Get security level for log type.
     *
     * @return int Security level (1, 2, or 3)
     */
    public static function getSecurityLevel(string $logName): int
    {
        return self::$securityLevels[$logName] ?? 1;
    }

    /**
     * Build hash chain by linking to previous log.
     *
     * Calculates SHA256 hash of current log data concatenated with
     * previous log's event_hash. Genesis logs have null previous_hash.
     */
    protected function buildHashChain(): void
    {
        // Validate tenant_id is set
        if ($this->tenant_id === null) {
            throw new \RuntimeException('Cannot build hash chain: tenant_id is required.');
        }

        // Find previous log in tenant's chain (including soft-deleted)
        $query = static::withTrashed()
            ->where('tenant_id', $this->tenant_id);

        // Exclude current record if it already exists
        if ($this->exists && $this->getKey() !== null) {
            $query->whereKeyNot($this->getKey());
        }

        /** @var Activity|null $previousLog */
        $previousLog = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->previous_hash = $previousLog?->event_hash;

        // Calculate event hash: SHA256(previous_hash + log_data)
        try {
            $logData = json_encode([
                'tenant_id' => $this->tenant_id,
                'log_name' => $this->log_name,
                'description' => $this->description,
                'subject_type' => $this->subject_type,
                'subject_id' => $this->subject_id,
                'causer_type' => $this->causer_type,
                'causer_id' => $this->causer_id,
                'properties' => $this->properties,
            ], JSON_THROW_ON_ERROR);

            $this->event_hash = hash('sha256', ($this->previous_hash ?? '').$logData);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Failed to encode activity log data for hashing.', 0, $exception);
        }
    }

    /**
     * Verify hash chain integrity.
     *
     * Checks if current log's event_hash correctly links to previous log.
     * Accepts orphaned genesis logs (when predecessor was deleted).
     *
     * @return bool True if chain is valid, false if tampered
     */
    public function verifyChain(): bool
    {
        // Validate tenant_id is set
        if ($this->tenant_id === null) {
            return false; // Cannot verify chain without tenant_id
        }

        // Orphaned genesis logs are valid (predecessor was legitimately deleted)
        if ($this->is_orphaned_genesis) {
            return true;
        }

        // Genesis log (first log in tenant's chain)
        if ($this->previous_hash === null) {
            return true;
        }

        // Find previous log (check active, soft-deleted, and archived logs)
        /** @var Activity|null $previousLog */
        $previousLog = static::withTrashed()
            ->where('tenant_id', $this->tenant_id)
            ->where('event_hash', $this->previous_hash)
            ->first();

        // If not in activity_log, check archive
        if (! $previousLog) {
            /** @var ActivityArchive|null $archivedLog */
            $archivedLog = ActivityArchive::where('tenant_id', $this->tenant_id)
                ->where('event_hash', $this->previous_hash)
                ->first();

            if (! $archivedLog) {
                return false; // Previous log missing (tampered!)
            }
        }

        // Recalculate event_hash and verify
        $logData = json_encode([
            'tenant_id' => $this->tenant_id,
            'log_name' => $this->log_name,
            'description' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'properties' => $this->properties,
        ], JSON_THROW_ON_ERROR);

        $calculatedHash = hash('sha256', ($this->previous_hash ?? '').$logData);

        return $calculatedHash === $this->event_hash;
    }

    /**
     * Verify Merkle proof for this log.
     *
     * Validates that this log's event_hash is part of the Merkle tree
     * with root stored in merkle_root. Stub implementation - full logic
     * will be implemented in PR-5.
     *
     * @return bool True if proof is valid, false otherwise
     */
    public function verifyMerkleProof(): bool
    {
        // Stub: Will be implemented in PR-5 (Merkle proof verification)
        if (! $this->merkle_root || ! $this->merkle_proof) {
            return false; // No Merkle data available
        }

        // TODO: Implement Merkle proof verification algorithm
        // - Iterate through proof siblings
        // - Hash left/right according to position
        // - Compare final hash with merkle_root

        return true; // Placeholder
    }

    /**
     * Verify OpenTimestamp proof for this log's Merkle root.
     *
     * Validates that the Merkle root is anchored to Bitcoin blockchain
     * via OpenTimestamp. Stub implementation - full logic will be
     * implemented in PR-6.
     *
     * @return bool True if OTS proof is valid, false otherwise
     */
    public function verifyOpenTimestamp(): bool
    {
        // Stub: Will be implemented in PR-6 (OpenTimestamp integration)
        if (! $this->ots_proof || ! $this->ots_confirmed_at) {
            return false; // No OTS data available
        }

        // TODO: Implement OpenTimestamp proof verification
        // - Load OTS proof from database
        // - Verify Bitcoin block attestation
        // - Check timestamp matches ots_confirmed_at

        return true; // Placeholder
    }

    /**
     * Get the model that caused the activity (polymorphic UUID support).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function causer(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the subject of the activity (polymorphic UUID support).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function subject(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the organizational unit this log belongs to.
     *
     * @return BelongsTo<OrganizationalUnit, $this>
     */
    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    /**
     * Get the tenant this log belongs to.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }
}
