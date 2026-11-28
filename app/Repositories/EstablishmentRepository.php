<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Organization\Establishment;
use App\Models\Organization\EstablishmentVersion;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class EstablishmentRepository
{
    public function create(int $tenantId): Establishment
    {
        return Establishment::query()->create(['tenant_id' => $tenantId]);
    }

    public function lock(int $tenantId, string $id): Establishment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Establishment locking requires an active transaction.');
        }
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Establishment::class);
        }

        return Establishment::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function latest(int $tenantId, string $id, ?DateTimeInterface $at = null): ?EstablishmentVersion
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $query = EstablishmentVersion::query()->where('tenant_id', $tenantId)->where('establishment_id', $id);
        if ($at !== null) {
            $query->where('effective_at', '<=', $at->format('Y-m-d H:i:s.uP'));
        }

        return $query->orderByDesc('effective_at')->first();
    }

    /** @param array{name: string, code: ?string, address: array<string, mixed>} $details */
    public function append(Establishment $establishment, array $details, bool $closed): EstablishmentVersion
    {
        if (! $establishment->wasRecentlyCreated) {
            // Write the locked identity row so PostgreSQL rejects an older
            // transaction snapshot that might otherwise accept a closed target.
            Establishment::query()->whereKey($establishment->id)->where('tenant_id', $establishment->tenant_id)
                ->update(['tenant_id' => $establishment->tenant_id]);
        }

        return EstablishmentVersion::query()->create([
            ...$details,
            'tenant_id' => $establishment->tenant_id,
            'establishment_id' => $establishment->id,
            'is_closed' => $closed,
        ])->refresh();
    }
}
