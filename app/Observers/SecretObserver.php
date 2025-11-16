<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Observers;

use App\Models\Secret;
use Illuminate\Support\Facades\DB;

/**
 * Observer for Secret model.
 *
 * Handles:
 * - Automatic blind index generation for title_idx field
 * - Full-text search vector updates for FTS
 */
class SecretObserver
{
    /**
     * Handle the Secret "creating" event.
     *
     * Generate blind index before inserting the record.
     */
    public function creating(Secret $secret): void
    {
        $this->updateBlindIndex($secret);
    }

    /**
     * Handle the Secret "updating" event.
     *
     * Regenerate blind index if title_plain changed.
     */
    public function updating(Secret $secret): void
    {
        // Only update if title_plain was modified
        if ($secret->isDirty('title_enc')) {
            $this->updateBlindIndex($secret);
        }
    }

    /**
     * Handle the Secret "saved" event.
     *
     * Update the full-text search vector after save.
     */
    public function saved(Secret $secret): void
    {
        $this->updateFullTextSearch($secret);
    }

    /**
     * Generate blind index for title field.
     *
     * Uses HMAC-SHA256 with the DEK as the key to create a searchable hash.
     * This allows equality searches without revealing the plaintext.
     *
     * @param Secret $secret
     */
    private function updateBlindIndex(Secret $secret): void
    {
        // Access via attribute accessor, not direct property
        $title = $secret->getAttribute('title_plain');

        if ($title === null || $title === '' || ! is_string($title)) {
            $secret->title_idx = '';
            return;
        }

        // Get the DEK from the tenant key
        $tenantKey = $secret->tenantKey;

        if ($tenantKey === null) {
            throw new \RuntimeException('Secret must have a tenant key');
        }

        $dek = $tenantKey->unwrapDek();

        // Generate HMAC-SHA256 blind index
        // Normalize: lowercase + trim for consistent search
        $normalized = strtolower(trim($title));
        $secret->title_idx = hash_hmac('sha256', $normalized, $dek);

        // Clear DEK from memory
        sodium_memzero($dek);
    }    /**
     * Update full-text search vector.
     *
     * Combines searchable fields into a PostgreSQL tsvector for FTS.
     * Runs after save to ensure all encrypted fields are persisted.
     *
     * @param Secret $secret
     */
    private function updateFullTextSearch(Secret $secret): void
    {
        // Build searchable text from plaintext accessors
        $searchableFields = [
            $secret->getAttribute('title_plain'),
            $secret->getAttribute('username_plain'),
            $secret->getAttribute('url_plain'),
            $secret->getAttribute('notes_plain'),
        ];

        // Filter nulls and join with spaces
        $searchText = implode(' ', array_filter($searchableFields));

        // Update tsvector using PostgreSQL's to_tsvector
        // 'english' is the text search configuration (language)
        DB::statement(
            'UPDATE secrets SET notes_tsv = to_tsvector(\'english\', ?) WHERE id = ?',
            [$searchText, $secret->id]
        );
    }
}
