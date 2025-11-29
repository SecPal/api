<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create guard_book_reports table for generated report snapshots.
 *
 * Reports are compiled views of guard book events for a specific time period.
 * They store denormalized event data as snapshots for historical integrity.
 *
 * Key Design Decisions (from ADR-007):
 * - report_data stores denormalized events (immutable snapshot)
 * - filter_criteria records what filters were applied during generation
 * - Unique report_number for external reference
 * - Status workflow: draft → finalized → submitted_to_customer → archived
 *
 * @see Issue #233: Guard Books Event Stream Implementation
 * @see ADR-007: Organizational Structure & Hierarchies
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('guard_book_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys');
            $table->foreignUuid('guard_book_id')
                ->constrained('guard_books')
                ->cascadeOnDelete();

            // Report identification
            $table->string('report_number')->unique(); // "GB-2025-001"
            $table->string('title'); // "Monatsbericht November 2025"

            // Time period covered by this report (flexible!)
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // Filter criteria applied when generating report
            $table->jsonb('filter_criteria')->nullable();
            // Example: {"event_types": ["incident", "patrol"], "severity": "high"}

            // Report content - denormalized snapshot of events
            $table->integer('total_events')->default(0);
            $table->jsonb('report_data')->nullable(); // Denormalized event data for historical integrity

            // Generation metadata
            $table->foreignUuid('generated_by_user_id')
                ->nullable()
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamp('generated_at');

            // Status workflow
            $table->string('status')->default('draft'); // draft, finalized, submitted_to_customer, archived

            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['tenant_id', 'guard_book_id'], 'guard_book_reports_tenant_book_idx');
            $table->index(['period_start', 'period_end'], 'guard_book_reports_period_idx');
            $table->index(['tenant_id', 'status'], 'guard_book_reports_tenant_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guard_book_reports');
    }
};
