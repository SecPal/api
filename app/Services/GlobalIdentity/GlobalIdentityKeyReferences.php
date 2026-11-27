<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use App\Models\GlobalIdentityWorkingKey;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class GlobalIdentityKeyReferences
{
    public function hasLiveReference(GlobalIdentityWorkingKeyContext $key): bool
    {
        $reference = $key->reference;
        $version = $reference->version;

        return match ($key->purpose) {
            GlobalIdentityWorkingKeyPurpose::EmailEncryption => $this->envelope(DB::table('users'), 'email_enc', $key),
            GlobalIdentityWorkingKeyPurpose::UserEmailLookup => DB::table('users')->where('email_idx_key_version', $version)->exists()
                || DB::table(GlobalEmailSchema::ROTATION_INDEXES)->where('index_key_version', $version)->exists()
                || DB::table(GlobalEmailSchema::RESERVATIONS)->where('index_key_version', $version)->exists(),
            GlobalIdentityWorkingKeyPurpose::UserEmailReservation => DB::table(GlobalEmailSchema::RESERVATIONS)
                ->where('reservation_key_version', $version)->exists(),
            GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret => $this->envelope(DB::table('global_identity_operations'), 'delivery_token_enc', $key)
                || DB::table('global_identity_operations')
                    ->where('delivery_key_id', $reference->id)->where('delivery_key_version', $version)
                    ->whereNotNull('token_hash')->where('expires_at', '>', now())
                    ->exists(),
            GlobalIdentityWorkingKeyPurpose::PendingEmailChangeEncryption => $this->operationRecipient(GlobalIdentityOperationType::PendingEmailChange, $key)
                || $this->envelope(DB::table('global_identity_rotation_reissues'), 'recipient_enc', $key),
            GlobalIdentityWorkingKeyPurpose::InvitationAddressEncryption => $this->operationRecipient(GlobalIdentityOperationType::Invitation, $key),
            GlobalIdentityWorkingKeyPurpose::MfaCredentials => $this->envelope(DB::table('two_factor_authentications'), 'shared_secret', $key)
                || $this->envelope(DB::table('two_factor_authentications'), 'recovery_codes', $key),
            GlobalIdentityWorkingKeyPurpose::AuthenticationRateLimit => false,
        };
    }

    private function operationRecipient(GlobalIdentityOperationType $type, GlobalIdentityWorkingKeyContext $key): bool
    {
        return $this->envelope(DB::table('global_identity_operations')->where('operation_type', $type->value), 'recipient_enc', $key);
    }

    /** @param 'email_enc'|'recipient_enc'|'delivery_token_enc'|'shared_secret'|'recovery_codes' $column */
    private function envelope(Builder $rows, string $column, GlobalIdentityWorkingKeyContext $key): bool
    {
        $data = match ($column) {
            'email_enc', 'recipient_enc', 'delivery_token_enc' => $column,
            'shared_secret' => 'shared_secret::jsonb',
            'recovery_codes' => 'recovery_codes::jsonb',
        };
        $id = "({$data})->>'key_id'";
        $version = "({$data})->>'key_version'";
        $rows->whereNotNull($column);

        // Only exact readable registry references can establish which key a
        // stored envelope needs. Full authentication remains with its codec.
        if ((clone $rows)->where(function (Builder $query) use ($data, $id, $version, $key): void {
            $query->whereRaw("jsonb_typeof(({$data})->'key_id') IS DISTINCT FROM 'string'")
                ->orWhereRaw("jsonb_typeof(({$data})->'key_version') IS DISTINCT FROM 'number'")
                ->orWhereNotExists(function (Builder $registry) use ($id, $version, $key): void {
                    $registry->from('global_identity_working_keys AS registry')
                        ->where('registry.purpose', $key->purpose->value)
                        ->whereIn('registry.state', [GlobalIdentityWorkingKey::WRITE_ACTIVE, GlobalIdentityWorkingKey::DECRYPT_ONLY])
                        ->whereRaw("registry.key_id::text = {$id} AND registry.key_version::text = {$version}");
                });
        })->exists()) {
            Log::warning('Global identity key inventory rejected.', [
                'purpose' => $key->purpose->value,
                'reason' => 'unrecognized_envelope_reference',
            ]);

            throw new GlobalIdentityKeyException('Global identity key inventory unavailable.');
        }

        return $rows->whereRaw("{$id} = ? AND {$version} = ?", [$key->reference->id, (string) $key->reference->version])->exists();
    }
}
