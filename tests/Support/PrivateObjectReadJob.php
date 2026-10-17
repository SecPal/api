<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Support;

use App\Models\TenantKey;
use App\Services\PrivateObjectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Actual Laravel Worker consumer; synthetic identities, no plaintext in payload. */
class PrivateObjectReadJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tenantId, public string $documentId, public string $expectedHash) {}

    public function handle(PrivateObjectService $objects): void
    {
        $bytes = $objects->read(TenantKey::findOrFail($this->tenantId), 'employee-document', $this->documentId);
        if (! hash_equals($this->expectedHash, hash('sha256', $bytes))) {
            throw new \RuntimeException('Native Worker ciphertext round trip failed');
        }
        DB::table('private_object_worker_results')->insert(['document_id' => $this->documentId]);
    }
}
