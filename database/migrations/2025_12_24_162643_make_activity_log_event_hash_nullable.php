<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Issue #408: Queue-based hash chain building requires event_hash to be nullable.
     *
     * BEFORE: Activity::creating hook builds hash synchronously (event_hash set BEFORE INSERT)
     * AFTER: Activity::created hook dispatches queue job (event_hash set AFTER INSERT)
     *
     * Timeline:
     * 1. Activity::create() → INSERT with event_hash=NULL
     * 2. Activity::created hook → Dispatch ProcessActivityHashChain job
     * 3. Job executes asynchronously → UPDATE with event_hash
     *
     * Result: event_hash must allow NULL temporarily (milliseconds to seconds depending on queue)
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('event_hash', 64)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('event_hash', 64)->nullable(false)->change();
        });
    }
};
