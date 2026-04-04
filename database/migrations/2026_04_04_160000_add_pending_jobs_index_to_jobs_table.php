<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a composite index on the jobs table to keep /health/ready pending-backlog
 * queries fast as the table grows.
 *
 * The RuntimeHeartbeatService::pendingJobsFor() query filters by queue name,
 * reserved_at IS NULL, and available_at <= NOW(). Without a covering index that
 * aligns with those predicates the database falls back to a sequential scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Composite index for unreserved pending-job backlog counts.
            // Postgres can use this for all three predicates in:
            //   WHERE queue IN (...) AND reserved_at IS NULL AND available_at <= ?
            $table->index(['queue', 'reserved_at', 'available_at'], 'jobs_queue_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex('jobs_queue_pending_idx');
        });
    }
};
