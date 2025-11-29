<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * GuardBookReport model representing generated report snapshots.
 *
 * Reports are compiled views of guard book events for a specific time period.
 * They store denormalized event data as snapshots for historical integrity.
 *
 * Key Design Decisions (from ADR-007):
 * - report_data contains denormalized events (immutable snapshot)
 * - Reports can be generated for any time period (not bound to months)
 * - filter_criteria records what filters were applied during generation
 *
 * Status Workflow:
 * - draft: Initial state, can be modified
 * - finalized: Locked, ready for distribution
 * - submitted_to_customer: Sent to external customer
 * - archived: Historical record, no longer active
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $guard_book_id Foreign key to guard_books
 * @property string $report_number Unique external identifier (e.g., "GB-2025-001")
 * @property string $title Report title
 * @property \Illuminate\Support\Carbon $period_start Start of the report period
 * @property \Illuminate\Support\Carbon $period_end End of the report period
 * @property array<string, mixed>|null $filter_criteria Filters applied during generation
 * @property int $total_events Number of events in the report
 * @property array<array{entry_id: string, event_type: string, occurred_at: string}>|null $report_data Denormalized event data
 * @property string|null $generated_by_user_id Foreign key to users
 * @property \Illuminate\Support\Carbon $generated_at When the report was generated
 * @property string $status Report status (draft, finalized, submitted_to_customer, archived)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this report belongs to
 * @property-read GuardBook $guardBook The parent guard book
 * @property-read User|null $generatedBy The user who generated the report
 */
class GuardBookReport extends Model
{
    /** @use HasFactory<\Database\Factories\GuardBookReportFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'guard_book_reports';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'guard_book_id',
        'report_number',
        'title',
        'period_start',
        'period_end',
        'filter_criteria',
        'total_events',
        'report_data',
        'generated_by_user_id',
        'generated_at',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'filter_criteria' => 'array',
            'total_events' => 'integer',
            'report_data' => 'array',
            'generated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this report.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the guard book this report belongs to.
     *
     * @return BelongsTo<GuardBook, $this>
     */
    public function guardBook(): BelongsTo
    {
        return $this->belongsTo(GuardBook::class, 'guard_book_id');
    }

    /**
     * Get the user who generated this report.
     *
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    /**
     * Get included event types from filter criteria.
     *
     * @return array<string>
     */
    public function getIncludedEventTypes(): array
    {
        if ($this->filter_criteria === null) {
            return [];
        }

        /** @var array<string> */
        $eventTypes = $this->filter_criteria['event_types'] ?? [];

        return $eventTypes;
    }

    /**
     * Check if a specific event type is included in this report.
     */
    public function includesEventType(string $eventType): bool
    {
        $eventTypes = $this->getIncludedEventTypes();

        // If no event types filter, all events are included (but return false for specific check)
        if (empty($eventTypes)) {
            return false;
        }

        return in_array($eventType, $eventTypes, true);
    }

    /**
     * Get a human-readable label for the report period.
     *
     * Examples:
     * - "01.11.2025 - 30.11.2025"
     * - "November 2025" (if full month)
     */
    public function getPeriodLabel(): string
    {
        $start = $this->period_start;
        $end = $this->period_end;

        // Check if it's a full month
        if ($this->isFullMonth($start, $end)) {
            return $start->translatedFormat('F Y');
        }

        // Check if it's a full week
        if ($this->isFullWeek($start, $end)) {
            return 'KW '.$start->weekOfYear.' '.$start->year;
        }

        // Default: date range format
        return $start->format('d.m.Y').' - '.$end->format('d.m.Y');
    }

    /**
     * Check if the period represents a full calendar month.
     */
    protected function isFullMonth(Carbon $start, Carbon $end): bool
    {
        return $start->day === 1
            && $end->day === $end->daysInMonth
            && $start->month === $end->month
            && $start->year === $end->year;
    }

    /**
     * Check if the period represents a full week (Monday to Sunday).
     */
    protected function isFullWeek(Carbon $start, Carbon $end): bool
    {
        if (! $start->isMonday() || ! $end->isSunday()) {
            return false;
        }

        return (int) $start->diffInDays($end) === 6;
    }
}
