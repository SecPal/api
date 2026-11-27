<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class GlobalIdentityKeyReferences
{
    public function hasLiveReference(GlobalIdentityWorkingKeyContext $key): bool
    {
        $reference = $key->reference;
        $version = $reference->version;

        return match ($key->purpose) {
            GlobalIdentityWorkingKeyPurpose::EmailEncryption => $this->envelope('users', 'email_enc', $reference),
            GlobalIdentityWorkingKeyPurpose::UserEmailLookup => DB::table('users')->where('email_idx_key_version', $version)->exists()
                || DB::table(GlobalEmailSchema::ROTATION_INDEXES)->where('index_key_version', $version)->exists()
                || DB::table(GlobalEmailSchema::RESERVATIONS)->where('index_key_version', $version)->exists(),
            GlobalIdentityWorkingKeyPurpose::UserEmailReservation => DB::table(GlobalEmailSchema::RESERVATIONS)
                ->where('reservation_key_version', $version)->exists(),
            GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret => DB::table('global_identity_operations')
                ->where('delivery_key_id', $reference->id)->where('delivery_key_version', $version)
                ->where(fn (Builder $query) => $query->whereNotNull('delivery_token_enc')
                    ->orWhere(fn (Builder $query) => $query->whereNotNull('token_hash')->where('expires_at', '>', now())))
                ->exists(),
            GlobalIdentityWorkingKeyPurpose::PendingEmailChangeEncryption,
            GlobalIdentityWorkingKeyPurpose::InvitationAddressEncryption => $this->envelope('global_identity_operations', 'recipient_enc', $reference)
                || $this->envelope('global_identity_rotation_reissues', 'recipient_enc', $reference),
            GlobalIdentityWorkingKeyPurpose::MfaCredentials => $this->envelope('two_factor_authentications', 'shared_secret', $reference)
                || $this->envelope('two_factor_authentications', 'recovery_codes', $reference),
            GlobalIdentityWorkingKeyPurpose::AuthenticationRateLimit => false,
        };
    }

    /** @param 'email_enc'|'recipient_enc'|'shared_secret'|'recovery_codes' $column */
    private function envelope(string $table, string $column, GlobalIdentityKeyReference $reference): bool
    {
        $data = match ($column) {
            'email_enc' => 'email_enc',
            'recipient_enc' => 'recipient_enc',
            'shared_secret' => 'shared_secret::jsonb',
            'recovery_codes' => 'recovery_codes::jsonb',
        };

        return DB::table($table)->whereNotNull($column)
            ->whereRaw("({$data})->>'key_id' = ? AND ({$data})->>'key_version' = ?",
                [$reference->id, (string) $reference->version])
            ->exists();
    }
}
