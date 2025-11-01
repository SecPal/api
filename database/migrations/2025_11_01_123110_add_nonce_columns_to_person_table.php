<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds nonce columns for tenant-DEK encryption (Option B).
     * Each encrypted field now has a separate nonce stored in binary format.
     */
    public function up(): void
    {
        Schema::table('person', function (Blueprint $table) {
            // Add nonce columns after their respective encrypted fields
            $table->binary('email_nonce')->after('email_enc');
            $table->binary('phone_nonce')->nullable()->after('phone_enc');
            $table->binary('address_nonce')->nullable()->after('address_enc');
            $table->binary('note_nonce')->nullable()->after('note_enc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('person', function (Blueprint $table) {
            $table->dropColumn(['email_nonce', 'phone_nonce', 'address_nonce', 'note_nonce']);
        });
    }
};
