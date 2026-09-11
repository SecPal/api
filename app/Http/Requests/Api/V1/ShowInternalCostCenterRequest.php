<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

final class ShowInternalCostCenterRequest extends InternalCostCenterRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['internalCostCenter' => ['required', 'uuid']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->routeValidationData(['internalCostCenter' => $this->route('internalCostCenter')]);
    }
}
