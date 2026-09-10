<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Contract;
use App\Models\ServiceBooking;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

final class ServiceBookingRepository
{
    private const INVOICED_HISTORY_GUARD_MESSAGE = 'invoiced service booking financial evidence is immutable';

    /** @return LengthAwarePaginator<int, ServiceBooking> */
    public function paginate(int $tenantId, int $page, int $perPage): LengthAwarePaginator
    {
        return ServiceBooking::query()
            ->forTenant($tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(['per_page' => $perPage]);
    }

    public function inspect(int $tenantId, string $serviceBookingId): ServiceBooking
    {
        return ServiceBooking::query()
            ->forTenant($tenantId)
            ->whereKey($serviceBookingId)
            ->firstOrFail();
    }

    public function lock(int $tenantId, string $serviceBookingId): ServiceBooking
    {
        return ServiceBooking::query()
            ->forTenant($tenantId)
            ->whereKey($serviceBookingId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function lockContract(int $tenantId, string $contractId): Contract
    {
        return Contract::query()
            ->forTenant($tenantId)
            ->whereKey($contractId)
            ->sharedLock()
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): ServiceBooking
    {
        return ServiceBooking::query()->create($attributes)->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function update(ServiceBooking $serviceBooking, array $attributes): ServiceBooking
    {
        $serviceBooking->fill($attributes)->save();

        return $serviceBooking->refresh();
    }

    public function isInvoicedHistoryGuardViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
        $databaseMessage = $exception->errorInfo[2] ?? '';

        return $sqlState === '23514'
            && is_string($databaseMessage)
            && str_contains($databaseMessage, self::INVOICED_HISTORY_GUARD_MESSAGE);
    }
}
