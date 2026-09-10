<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

final class ContractRepository
{
    private const HISTORY_GUARD_MESSAGE = 'contract customer and currency are immutable after booking history exists';

    /** @return LengthAwarePaginator<int, Contract> */
    public function paginate(int $tenantId, int $page, int $perPage): LengthAwarePaginator
    {
        return Contract::query()
            ->forTenant($tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(['per_page' => $perPage]);
    }

    public function inspect(int $tenantId, string $contractId): Contract
    {
        return Contract::query()
            ->forTenant($tenantId)
            ->whereKey($contractId)
            ->firstOrFail();
    }

    public function lock(int $tenantId, string $contractId): Contract
    {
        return Contract::query()
            ->forTenant($tenantId)
            ->whereKey($contractId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function lockCustomer(int $tenantId, string $customerId): Customer
    {
        return Customer::query()
            ->forTenant($tenantId)
            ->whereKey($customerId)
            ->sharedLock()
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Contract
    {
        return Contract::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Contract $contract, array $attributes): Contract
    {
        $contract->fill($attributes)->save();

        return $contract->refresh();
    }

    public function hasBookingHistory(Contract $contract): bool
    {
        return $contract->serviceBookings()->exists();
    }

    public function isHistoryGuardViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
        $databaseMessage = $exception->errorInfo[2] ?? '';

        return $sqlState === '23514'
            && is_string($databaseMessage)
            && str_contains($databaseMessage, self::HISTORY_GUARD_MESSAGE);
    }
}
