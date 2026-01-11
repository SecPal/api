<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Custom Activity model extending Spatie with SecPal forensic extensions.
 *
 * Features:
 * - Automatic tenant/org scope injection
 * - Hash chain building (queue-based, race-condition-free) - Issue #408
 * - Merkle tree batching (hierarchical verification)
 * - OpenTimestamp integration (blockchain anchoring)
 * - Legal retention periods (BewachV § 21 Abs. 4, HGB § 257, AO § 147)
 *
 * Hash Chain Architecture (Issue #408):
 * - Activity INSERT happens first (event_hash=NULL initially)
 * - ProcessActivityHashChain job dispatched in `created` event
 * - Job uses DB transaction + lockForUpdate() for race-free processing
 * - event_hash updated via DB::table()->update() (bypasses Eloquent events)
 * - Tests MUST call $activity->refresh() to reload updated event_hash
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
 * @property string|null $event_hash - Nullable during initial creation (updated by job)
 * @property string|null $merkle_root
 * @property string|null $merkle_batch_id
 * @property array<string, mixed>|null $merkle_proof
 * @property string|null $ots_proof
 * @property \Carbon\Carbon|null $ots_submitted_at
 * @property \Carbon\Carbon|null $ots_confirmed_at
 * @property bool $is_orphaned_genesis
 * @property string|null $orphaned_reason
 * @property \Carbon\Carbon|null $orphaned_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @see ADR-010 Activity Logging & Audit Trail Strategy
 * @see Issue #387 PR-2: Implement custom Activity model with hash chain
 * @see Issue #408 PR-3: Queue-based activity hash chain building (race-condition-free)
 */
class Activity extends SpatieActivity
{
    /** @use HasFactory<\Database\Factories\ActivityFactory> */
    use HasFactory;

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
        'merkle_batch_count',
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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'causer_id' => 'string',
        'subject_id' => 'string',
    ];

    /**
     * Get the OTS proof attribute.
     *
     * OTS proofs are stored as base64-encoded text in PostgreSQL.
     * This accessor converts them back to binary strings automatically.
     *
     * @param  string|null  $value
     */
    public function getOtsProofAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }

    /**
     * Set the OTS proof attribute.
     *
     * OTS proofs are binary data, but PostgreSQL binary columns are problematic.
     * This mutator encodes binary data as base64 before storage.
     *
     * @param  string|null  $value
     */
    public function setOtsProofAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['ots_proof'] = null;

            return;
        }

        $this->attributes['ots_proof'] = base64_encode($value);
    }

    /**
     * Retention periods per log type (legal compliance).
     *
     * All logs have identical security measures:
     * - Hash Chain: Sequential integrity verification
     * - Merkle Tree: Batch verification (hourly)
     * - OpenTimestamp: Bitcoin blockchain anchoring
     *
     * Retention duration based solely on legal requirements:
     *
     * Legal References:
     * - BewachV §21 Abs. 4: 3 years minimum for Bewachungsgewerbe
     *   Retained until end of Nth following calendar year
     * - HGB §257 Abs. 4: 8 years for Buchungsbelege (changed from 10 in 2015),
     *   10 years for Jahresabschlüsse
     * - AO §147 Abs. 3: 8 years for tax-relevant documents
     *
     * @var array<string, int> Mapping of log_name to retention years
     */
    protected static array $retentionYears = [
        // 3 Years: BewachV §21 Abs. 4 - Bewachungsgewerbe
        'default' => 3,
        'shift_management' => 3,
        'guard_book' => 3,
        'security' => 3,
        'authentication' => 3,
        'rbac_changes' => 3,
        'scope_changes' => 3,
        'customer_changes' => 3,
        'site_management' => 3,
        'employee_changes' => 3,
        'hr_access' => 3,
        'works_council_access' => 3,
        'sensitive_access' => 3,
        'guard_book_event' => 3,

        // 8 Years: HGB §257 & AO §147 - Buchungsbelege
        'invoice_generated' => 8,
        'payment_processed' => 8,
        'contract_change' => 8,

        // 10 Years: HGB §257 - Jahresabschlüsse
        'annual_closing' => 10,
    ];

    /**
     * Bootstrap the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Activity $activity) {
            // Priority 1: Try to get tenant_id from subject model (for auto-logging via LogsActivity trait)
            // First try to get the subject from the relation (if it's been set by Spatie)
            $subjectModel = null;
            // Try to get the loaded relation first (avoids DB query)
            if ($activity->relationLoaded('subject')) {
                $subjectModel = $activity->getRelation('subject');
            }
            // If relation not loaded, will try DB query below

            // If relation not loaded, try to query the database
            if (! $subjectModel && $activity->subject_type && $activity->subject_id) {
                /** @var class-string<\Illuminate\Database\Eloquent\Model> $subjectType */
                $subjectType = $activity->subject_type;
                if (class_exists($subjectType)) {
                    // Use withTrashed() to find soft-deleted models (e.g., during 'deleted' event)
                    if (method_exists($subjectType, 'withTrashed')) {
                        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
                        $query = $subjectType::withTrashed();
                        $subjectModel = $query->find($activity->subject_id);
                    } else {
                        $subjectModel = $subjectType::find($activity->subject_id);
                    }
                }
            }

            // Now extract tenant_id from the subject model
            if ($subjectModel instanceof \Illuminate\Database\Eloquent\Model) {
                /** @var mixed $subjectTenantId */
                $subjectTenantId = $subjectModel->getAttribute('tenant_id');
                if (is_int($subjectTenantId)) {
                    $activity->tenant_id = $subjectTenantId;
                }
            }

            // Priority 2: Fall back to authenticated user if tenant_id still not set
            /** @var \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard $guard */
            $guard = auth();
            if (! $activity->tenant_id && $guard->check() && $guard->user() !== null) {
                /** @var User $user */
                $user = $guard->user();
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

            // Validate organizational_unit_id belongs to same tenant (Issue #402)
            if ($activity->organizational_unit_id !== null) {
                $activity->validateOrganizationalUnit();
            }

            // Capture request metadata
            if (! $activity->ip_address && request()->ip()) {
                $activity->ip_address = request()->ip();
            }

            if (! $activity->user_agent && request()->userAgent()) {
                $activity->user_agent = request()->userAgent();
            }

            // NOTE: Hash chain building moved to 'created' hook (Issue #408)
            // Reason: Queue-based processing requires Activity ID (from INSERT)
            // buildHashChain() is now handled by ProcessActivityHashChain job
        });

        static::created(function (Activity $activity) {
            // Dispatch queue job for hash chain building (Issue #408)
            // Queue ensures sequential processing per tenant (eliminates race condition)
            if ($activity->tenant_id === null) {
                Log::warning('Activity created without tenant_id - skipping hash chain', [
                    'activity_id' => $activity->id,
                ]);

                return;
            }

            // Prepare activity data for job (extract relevant attributes)
            $activityData = [
                'id' => $activity->id,
                'tenant_id' => $activity->tenant_id,
                'organizational_unit_id' => $activity->organizational_unit_id,
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'properties' => $activity->properties,
                'event' => $activity->event,
                'batch_uuid' => $activity->batch_uuid,
                'created_at' => $activity->created_at->toIso8601String(), // Include timestamp for hash uniqueness
            ];

            // Dispatch queue job for hash chain building
            // Testing: dispatchSync() executes job immediately (required for tests)
            // Production: dispatchSync() with advisory lock ensures sequential processing
            //
            // IMPORTANT: We use dispatchSync() instead of dispatch() because:
            // 1. Advisory lock in ProcessActivityHashChain ensures atomicity
            // 2. Multiple activities created in one request (e.g., Spatie + GDPR log)
            //    must be processed sequentially, not queued for later
            // 3. Queue workers would process jobs async, but we need sync processing
            //    within the same request to maintain proper hash chain
            //
            // The advisory lock (pg_advisory_xact_lock) ensures that even with dispatchSync,
            // multiple jobs for the same tenant wait for each other.
            \App\Jobs\ProcessActivityHashChain::dispatchSync($activity->tenant_id, $activityData);
        });
    }

    /**
     * Get retention period in years for a log type.
     *
     * All logs have identical security measures (Hash Chain + Merkle Tree + OTS).
     * This method returns the legal retention period based on applicable law.
     *
     * @param  string|null  $logName  The log type name. If null, returns all retention periods.
     * @return int|array<string, int> Retention period in years, or array of all periods
     *
     * @see BewachV §21 Abs. 4 - 3 years for Bewachungsgewerbe
     * @see HGB §257 Abs. 4 - 8/10 years for commercial records
     * @see AO §147 Abs. 3 - 8 years for tax-relevant documents
     */
    public static function getRetentionYears(?string $logName = null): int|array
    {
        if ($logName === null) {
            return self::$retentionYears;
        }

        return self::$retentionYears[$logName] ?? 3;
    }

    /**
     * Validate that organizational unit belongs to same tenant.
     *
     * Extracted to dedicated method for better separation of concerns
     * and testability (Copilot Review #2644220004).
     *
     * Caches tenant_id to avoid repeated property access (Issue #402).
     *
     * Provides detailed error messages with IDs for debugging.
     *
     * @throws \InvalidArgumentException If tenant_id is not set or OU validation fails
     */
    protected function validateOrganizationalUnit(): void
    {
        // Cache tenant_id to avoid repeated property access (Issue #402)
        $tenantId = $this->tenant_id;

        if ($tenantId === null) {
            throw new \InvalidArgumentException(
                'Activity tenant_id must be set before validating organizational_unit_id'
            );
        }

        $organizationalUnit = OrganizationalUnit::query()
            ->select(['id', 'tenant_id'])
            ->find($this->organizational_unit_id);

        if ($organizationalUnit === null) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Organizational unit '%s' does not exist",
                    $this->organizational_unit_id
                )
            );
        }

        if ((int) $organizationalUnit->tenant_id !== (int) $tenantId) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Organizational unit '%s' belongs to tenant '%s' but activity log belongs to tenant '%s'",
                    $this->organizational_unit_id,
                    (string) $organizationalUnit->tenant_id,
                    (string) $tenantId
                )
            );
        }
    }

    /**
     * Build hash chain by linking to previous log.
     *
     * Calculates SHA256 hash of current log data concatenated with
     * previous log's event_hash. Genesis logs have null previous_hash.
     *
     * Uses PostgreSQL row-level locking within a transaction to reduce
     * race conditions when multiple logs are created concurrently (Issue #402)
     * by applying DB::transaction() + lockForUpdate() around the lookup of
     * the previous activity for the same tenant / organizational unit.
     *
     * Unlike Customer::generateCustomerNumber() and Site::generateSiteNumber(),
     * which are static and control when they run, this method executes from a
     * 'creating' Eloquent event hook before the INSERT. As a result, it cannot
     * provide fully atomic sequencing, and the race window described below
     * still exists even though row-level locking is used.
     *
     * KNOWN LIMITATION (Copilot Review #2644159834):
     * This implementation has a theoretical race condition window because:
     * 1. buildHashChain() runs in 'creating' event hook (BEFORE INSERT)
     * 2. Transaction in this method commits BEFORE the actual INSERT
     * 3. Two concurrent creates can read same previous_hash → broken chain
     *
     * Mitigation: lockForUpdate() reduces window to microseconds.
     * Impact: Low in practice (< 10 activities/sec per tenant typical)
     * Future: Epic #408 will refactor to queue-based sequential processing
     *
     * @see Epic #408 Queue-based hash chain building (100% race-free)
     * @see Issue #402 Original security & locking implementation
     */
    protected function buildHashChain(): void
    {
        // Validate tenant_id is set
        if ($this->tenant_id === null) {
            throw new \RuntimeException('Cannot build hash chain: tenant_id is required.');
        }

        // Use transaction to ensure lockForUpdate() works correctly
        // Without transaction, pessimistic lock has no effect
        DB::transaction(function (): void {
            // Find previous log in tenant's chain
            // lockForUpdate() prevents concurrent transactions from seeing same "previous log"
            $query = static::where('tenant_id', $this->tenant_id)
                ->lockForUpdate(); // Row-level lock (SELECT ... FOR UPDATE)

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
                    'created_at' => $this->created_at->toIso8601String(), // Timestamp ensures hash uniqueness
                ], JSON_THROW_ON_ERROR);

                $this->event_hash = hash('sha256', ($this->previous_hash ?? '').$logData);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('Failed to encode activity log data for hashing.', 0, $exception);
            }
        });
    }

    /**
     * Verify hash chain integrity.
     *
     * Checks if current log's event_hash correctly links to previous log.
     * Accepts orphaned genesis logs (when predecessor was deleted).
     *
     * @return bool|null True if chain is valid, false if tampered, null if not yet processed
     */
    public function verifyChain(): ?bool
    {
        // Activity not yet processed by hash chain job
        if ($this->event_hash === null) {
            return null;
        }

        // Validate tenant_id is set
        if ($this->tenant_id === null) {
            return false; // Cannot verify chain without tenant_id
        }

        // Orphaned genesis logs are valid (predecessor was legitimately deleted)
        if ($this->is_orphaned_genesis) {
            return true;
        }

        // Recalculate event_hash and verify (for both genesis and chained logs)
        $logData = json_encode([
            'tenant_id' => $this->tenant_id,
            'log_name' => $this->log_name,
            'description' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'properties' => $this->properties,
            'created_at' => $this->created_at->toIso8601String(), // Timestamp ensures hash uniqueness
        ], JSON_THROW_ON_ERROR);

        $calculatedHash = hash('sha256', ($this->previous_hash ?? '').$logData);

        // For genesis logs (previous_hash === null), only verify own data
        if ($this->previous_hash === null) {
            return $calculatedHash === $this->event_hash;
        }

        // For chained logs, verify predecessor exists AND own data is correct
        // Find previous log (check active and archived logs)
        /** @var Activity|null $previousLog */
        $previousLog = static::where('tenant_id', $this->tenant_id)
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

        return $calculatedHash === $this->event_hash;
    }

    /**
     * Verify chain link integrity (does previous_hash point to valid predecessor?).
     *
     * This checks if the chain link between this activity and its predecessor is intact.
     * Returns false if:
     * - previous_hash exists but no predecessor with that event_hash exists
     * - previous_hash points to a predecessor whose event_hash has been modified
     *
     * @return bool|null True if link is valid, false if broken, null if not yet processed
     */
    public function verifyChainLink(): ?bool
    {
        // Activity not yet processed by hash chain job
        if ($this->event_hash === null) {
            return null;
        }

        // Orphaned genesis logs are valid (predecessor was legitimately deleted)
        if ($this->is_orphaned_genesis) {
            return true;
        }

        // Genesis log check: If previous_hash is NULL, verify it's a LEGITIMATE genesis
        if ($this->previous_hash === null) {
            // Check if there's an earlier activity with same log_name and tenant_id
            $earlierActivity = static::where('tenant_id', $this->tenant_id)
                ->where('log_name', $this->log_name)
                ->where('id', '<', $this->id)
                ->orderBy('id', 'desc')
                ->first();

            // If an earlier activity exists, this genesis is ILLEGITIMATE (chain was broken!)
            if ($earlierActivity) {
                return false; // This should NOT be a genesis log!
            }

            // Also check archive for earlier activities
            $archivedEarlier = ActivityArchive::where('tenant_id', $this->tenant_id)
                ->where('log_name', $this->log_name)
                ->where('id', '<', $this->id)
                ->orderBy('id', 'desc')
                ->first();

            if ($archivedEarlier) {
                return false; // This should NOT be a genesis log!
            }

            // No earlier activity found - this is a legitimate genesis log
            return true;
        }

        // Find previous log by event_hash (check active and archived logs)
        /** @var Activity|null $previousLog */
        $previousLog = static::where('tenant_id', $this->tenant_id)
            ->where('event_hash', $this->previous_hash)
            ->first();

        // If not in activity_log, check archive
        if (! $previousLog) {
            /** @var ActivityArchive|null $archivedLog */
            $archivedLog = ActivityArchive::where('tenant_id', $this->tenant_id)
                ->where('event_hash', $this->previous_hash)
                ->first();

            if (! $archivedLog) {
                return false; // Previous log missing or hash modified (chain link broken!)
            }
        }

        // Chain link is intact if predecessor exists with matching event_hash
        return true;
    }

    /**
     * Verify Merkle proof for this log.
     *
     * Validates that this log's event_hash is part of the Merkle tree
     * with root stored in merkle_root. Iterates through sibling hashes
     * in the proof, hashing left/right according to position, and compares
     * the final computed hash with the stored merkle_root.
     *
     * Additionally checks batch integrity: if merkle_batch_count is set,
     * verifies that the actual number of activities in the batch matches
     * the expected count. If activities are missing, the batch is incomplete
     * and considered invalid (forensic security: detects deleted activities).
     *
     * @return bool|null True if proof is valid, false if invalid, null if not yet processed
     */
    public function verifyMerkleProof(): ?bool
    {
        if (! $this->merkle_root || $this->merkle_proof === null) {
            return null; // Merkle tree not yet built (pending)
        }

        // Check batch integrity: detect if activities were deleted from batch
        if ($this->merkle_batch_count !== null && $this->merkle_batch_id !== null) {
            $actualCount = static::where('merkle_batch_id', $this->merkle_batch_id)->count();

            if ($actualCount < $this->merkle_batch_count) {
                // Activities missing from batch - forensic integrity violated!
                return false;
            }
        }

        // Start with leaf hash (this log's event_hash)
        $currentHash = $this->event_hash;

        // Single-leaf tree: empty proof means this is the only leaf, root = leaf
        if (is_array($this->merkle_proof) && count($this->merkle_proof) === 0) {
            return $currentHash === $this->merkle_root;
        }

        // Iterate through proof siblings, hashing up the tree
        foreach ($this->merkle_proof as $sibling) {
            if (! is_array($sibling)) {
                return false; // Invalid proof format
            }

            $siblingHash = $sibling['hash'] ?? null;
            $position = $sibling['position'] ?? null;

            if (! is_string($siblingHash) || ! is_string($position)) {
                return false; // Invalid proof format
            }

            // Hash with sibling according to position
            if ($position === 'left') {
                // Sibling is on left: hash(sibling + current)
                $currentHash = hash('sha256', $siblingHash.$currentHash);
            } else {
                // Sibling is on right: hash(current + sibling)
                $currentHash = hash('sha256', $currentHash.$siblingHash);
            }
        }

        // Compare computed root with stored root
        return $currentHash === $this->merkle_root;
    }

    /**
     * Verify OpenTimestamp proof for this log's Merkle root.
     *
     * Validates that the Merkle root is anchored to Bitcoin blockchain
     * via OpenTimestamp. Uses OpenTimestampService to verify proof structure
     * and Bitcoin attestation.
     *
     * @return bool|null True if OTS proof is valid and Bitcoin-confirmed, false if invalid, null if not yet confirmed
     *
     * @see ADR-010 Section 6: OpenTimestamp Integration
     * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
     */
    public function verifyOpenTimestamp(): ?bool
    {
        // Require confirmed proof
        if (! $this->ots_proof || ! $this->ots_confirmed_at || ! $this->merkle_root) {
            return null; // OTS not yet confirmed (pending) or not applicable
        }

        try {
            /** @var \App\Services\OpenTimestampService $otsService */
            $otsService = app(\App\Services\OpenTimestampService::class);

            return $otsService->verify($this->ots_proof, $this->merkle_root);
        } catch (\Exception $e) {
            // Log error but return false (don't expose exception)
            \Illuminate\Support\Facades\Log::warning('OpenTimestamp verification failed', [
                'activity_id' => $this->id,
                'tenant_id' => $this->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
