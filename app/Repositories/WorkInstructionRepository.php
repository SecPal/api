<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\WorkInstruction;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

final class WorkInstructionRepository
{
    private const TENANT_NUMBER_UNIQUE = 'work_instructions_tenant_number_unique';

    private const NUMBER_IMMUTABILITY_MESSAGE = 'work instruction numbers are immutable';

    private const LIFECYCLE_CONSTRAINT = 'work_instructions_lifecycle_timestamps_check';

    /** @return LengthAwarePaginator<int, WorkInstruction> */
    public function paginate(int $tenantId, int $page, int $perPage): LengthAwarePaginator
    {
        return WorkInstruction::query()
            ->forTenant($tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(['per_page' => $perPage]);
    }

    public function inspect(int $tenantId, string $workInstructionId): WorkInstruction
    {
        return WorkInstruction::query()
            ->forTenant($tenantId)
            ->whereKey($workInstructionId)
            ->firstOrFail();
    }

    public function lock(int $tenantId, string $workInstructionId): WorkInstruction
    {
        return WorkInstruction::query()
            ->forTenant($tenantId)
            ->whereKey($workInstructionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): WorkInstruction
    {
        return WorkInstruction::query()->create($attributes)->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function update(WorkInstruction $workInstruction, array $attributes): WorkInstruction
    {
        $workInstruction->fill($attributes)->save();

        return $workInstruction->refresh();
    }

    public function isDuplicateInstructionNumber(QueryException $exception): bool
    {
        return $this->matches($exception, '23505', self::TENANT_NUMBER_UNIQUE);
    }

    public function isRecognizedStateConflict(QueryException $exception): bool
    {
        return $this->matches($exception, '23514', self::NUMBER_IMMUTABILITY_MESSAGE)
            || $this->matches($exception, '23514', self::LIFECYCLE_CONSTRAINT);
    }

    private function matches(QueryException $exception, string $sqlState, string $fragment): bool
    {
        $actualState = $exception->errorInfo[0] ?? $exception->getCode();
        $databaseMessage = $exception->errorInfo[2] ?? '';

        return $actualState === $sqlState
            && is_string($databaseMessage)
            && str_contains($databaseMessage, $fragment);
    }
}
