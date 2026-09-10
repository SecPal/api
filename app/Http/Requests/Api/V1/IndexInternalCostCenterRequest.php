<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

final class IndexInternalCostCenterRequest extends InternalCostCenterRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->closedQueryRules([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ], ['page', 'per_page']);
    }
}
