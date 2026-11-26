<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Exceptions\CorruptedEncryptedAttributeException;
use App\Models\Person;
use App\Models\Tenant;
use App\Models\TenantKey;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Rotate a tenant's DEK when only legacy Person records use it.
 */
class RotateDekCommand extends Command
{
    /** @var string */
    protected $signature = 'keys:rotate-dek {tenant : The tenant ID}';

    /** @var string */
    protected $description = 'Rotate the DEK for a tenant with only legacy Person ciphertext';

    public function handle(): int
    {
        $argument = $this->argument('tenant');
        $tenantId = Tenant::parseId($argument);

        if ($tenantId === null) {
            $this->error('The tenant argument must be a single tenant ID.');

            return Command::FAILURE;
        }

        $this->info("Starting DEK rotation for tenant {$tenantId}...");

        try {
            $result = DB::transaction(function () use ($tenantId): ?array {
                $key = TenantKey::query()->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();

                // Include soft-deleted records and employees that own encrypted files.
                foreach (['employees', 'employee_addresses', 'customer_establishments', 'push_device_registrations'] as $table) {
                    if (DB::table($table)->where('tenant_id', $tenantId)->exists()) {
                        $this->error("DEK rotation requires support for ciphertext owned by {$table}.");

                        return null;
                    }
                }

                // Authenticate the assigned DEK even when there are no Person rows.
                $oldDek = $key->unwrapDek();
                sodium_memzero($oldDek);
                $newDek = sodium_crypto_secretbox_keygen();
                $this->info('✅ Generated new DEK');

                try {
                    $count = 0;
                    $persons = Person::where('tenant_id', $tenantId)->lockForUpdate()->lazyById();
                    foreach ($persons as $person) {
                        $this->reencrypt($person, $newDek);
                        $count++;
                    }

                    $oldVersion = $key->key_version;
                    $key->replaceDek($newDek);
                } finally {
                    sodium_memzero($newDek);
                }

                return [$count, $oldVersion, $key->key_version];
            });

            if ($result === null) {
                return Command::FAILURE;
            }

            [$count, $oldVersion, $newVersion] = $result;
            $this->info("✅ Re-encrypted {$count} record(s)");
            $this->info("✅ Updated tenant {$tenantId}: key_version {$oldVersion} → {$newVersion}");
            $this->info('✅ DEK rotation complete!');

            return Command::SUCCESS;
        } catch (ModelNotFoundException) {
            $this->error("Tenant {$tenantId} not found.");
        } catch (CorruptedEncryptedAttributeException) {
            $this->error('DEK rotation failed because a Person ciphertext could not be authenticated.');
        } catch (Throwable) {
            $this->error('DEK rotation failed. Verify the tenant key and database before retrying.');
        }

        return Command::FAILURE;
    }

    private function reencrypt(Person $person, #[SensitiveParameter] string $newDek): void
    {
        $updates = [];

        foreach (['email_enc', 'phone_enc', 'note_enc'] as $field) {
            $plaintext = $person->getAttribute($field);

            if ($plaintext === null) {
                continue;
            }

            if (! is_string($plaintext)) {
                throw new CorruptedEncryptedAttributeException("Unexpected decrypted value for {$field}.");
            }

            try {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $updates[$field] = json_encode([
                    'ciphertext' => base64_encode(sodium_crypto_secretbox($plaintext, $nonce, $newDek)),
                    'nonce' => base64_encode($nonce),
                ], JSON_THROW_ON_ERROR);
            } finally {
                sodium_memzero($plaintext);
            }
        }

        // Write the envelope directly to bypass casts and observers.
        if ($updates !== []) {
            Person::whereKey($person->id)->update($updates);
        }
    }
}
