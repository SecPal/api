<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\WorkInstructionStandardBlock;
use Illuminate\Pagination\LengthAwarePaginator;

final class WorkInstructionStandardBlockRepository
{
    /** @return LengthAwarePaginator<int, WorkInstructionStandardBlock> */
    public function paginate(int $page, int $perPage): LengthAwarePaginator
    {
        return WorkInstructionStandardBlock::query()
            ->with('translations')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(['per_page' => $perPage]);
    }

    public function inspect(string $id): WorkInstructionStandardBlock
    {
        return WorkInstructionStandardBlock::query()
            ->with('translations')
            ->whereKey($id)
            ->firstOrFail();
    }
}
