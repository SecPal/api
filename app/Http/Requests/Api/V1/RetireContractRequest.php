<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

final class RetireContractRequest extends ContractRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return array_merge(
            ['contract' => ['required', 'uuid']],
            $this->closedBodyRules([], []),
        );
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->routeValidationData(['contract' => $this->route('contract')]);
    }
}
