<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\InternalCostCenter;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

final class InternalCostCenterRepository
{
    private const UNIQUE_NAME = 'internal_cost_centers_tenant_code_unique';

    /** @return LengthAwarePaginator<int, InternalCostCenter> */
    public function paginate(int $tenantId, int $page, int $perPage): LengthAwarePaginator
    {
        return InternalCostCenter::query()
            ->forTenant($tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(['per_page' => $perPage]);
    }

    public function inspect(int $tenantId, string $internalCostCenterId): InternalCostCenter
    {
        return InternalCostCenter::query()
            ->forTenant($tenantId)
            ->whereKey($internalCostCenterId)
            ->firstOrFail();
    }

    public function lock(int $tenantId, string $internalCostCenterId): InternalCostCenter
    {
        return InternalCostCenter::query()
            ->forTenant($tenantId)
            ->whereKey($internalCostCenterId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): InternalCostCenter
    {
        return InternalCostCenter::query()->create($attributes)->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function update(InternalCostCenter $internalCostCenter, array $attributes): InternalCostCenter
    {
        $internalCostCenter->fill($attributes)->save();

        return $internalCostCenter->refresh();
    }

    public function isDuplicateCode(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
        $databaseMessage = $exception->errorInfo[2] ?? '';

        return $sqlState === '23505'
            && is_string($databaseMessage)
            && str_contains($databaseMessage, self::UNIQUE_NAME);
    }
}
