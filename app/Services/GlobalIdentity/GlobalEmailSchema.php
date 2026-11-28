<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GlobalEmailSchema
{
    public const RESERVATIONS = 'global_identity_email_reservations';

    public const ROTATION_INDEXES = 'global_identity_email_rotation_indexes';

    /** Install the encrypted identity fields on the clean users table. */
    public function apply(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \LogicException('Global identity schema requires PostgreSQL.');
        }
        DB::transaction(function (): void {
            Schema::table('users', function (Blueprint $table): void {
                $table->jsonb('email_enc');
                $table->binary('email_idx');
                $table->integer('email_normalization_version');
                $table->bigInteger('email_idx_key_version');
                $table->bigInteger('email_version');
                $table->unique(['email_normalization_version', 'email_idx_key_version', 'email_idx'], 'users_global_email_unique');
            });
            DB::statement(<<<'SQL'
                ALTER TABLE users ADD CONSTRAINT users_global_email_metadata CHECK (
                    octet_length(email_idx) = 32 AND jsonb_typeof(email_enc) = 'object'
                    AND email_normalization_version > 0 AND email_idx_key_version > 0 AND email_version > 0
                )
                SQL);

            Schema::create(self::RESERVATIONS, function (Blueprint $table): void {
                $table->uuid('operation_id');
                $table->integer('normalization_version');
                $table->bigInteger('index_key_version');
                $table->bigInteger('reservation_key_version');
                $table->binary('digest');
                $table->unique(['operation_id', 'normalization_version', 'index_key_version'], 'global_email_reservation_pair');
                $table->unique(
                    ['normalization_version', 'index_key_version', 'reservation_key_version', 'digest'],
                    'global_email_reservation_unique',
                );
            });
            DB::statement(<<<'SQL'
                ALTER TABLE global_identity_email_reservations ADD CONSTRAINT global_email_reservation_metadata CHECK (
                    octet_length(digest) = 32 AND normalization_version > 0
                    AND index_key_version > 0 AND reservation_key_version > 0
                )
                SQL);

            Schema::create(self::ROTATION_INDEXES, function (Blueprint $table): void {
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->integer('normalization_version');
                $table->bigInteger('index_key_version');
                $table->binary('email_idx');
                $table->unique(['user_id', 'normalization_version', 'index_key_version'], 'global_email_rotation_pair');
                $table->unique(['normalization_version', 'index_key_version', 'email_idx'], 'global_email_rotation_unique');
            });
            DB::statement(<<<'SQL'
                ALTER TABLE global_identity_email_rotation_indexes ADD CONSTRAINT global_email_rotation_metadata CHECK (
                    octet_length(email_idx) = 32 AND normalization_version > 0 AND index_key_version > 0
                )
                SQL);
        });
    }

    public function dropSidecars(): void
    {
        Schema::dropIfExists(self::ROTATION_INDEXES);
        Schema::dropIfExists(self::RESERVATIONS);
    }
}
