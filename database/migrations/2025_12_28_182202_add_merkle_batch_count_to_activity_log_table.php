<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds merkle_batch_count column to track the expected number of activities
     * in each Merkle batch. This enables detection of deleted activities from batches.
     *
     * Forensic Security: If actual count < merkle_batch_count, the batch is incomplete
     * and activities may have been deleted to hide evidence.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Expected number of activities in this Merkle batch
            // NULL = batch count not yet recorded (for backwards compatibility)
            // Set when BuildMerkleTreeBatch job creates the batch
            $table->unsignedInteger('merkle_batch_count')->nullable()->after('merkle_batch_id');

            // Add index for batch integrity queries
            $table->index(['merkle_batch_id', 'merkle_batch_count'], 'idx_merkle_batch_integrity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('idx_merkle_batch_integrity');
            $table->dropColumn('merkle_batch_count');
        });
    }
};
