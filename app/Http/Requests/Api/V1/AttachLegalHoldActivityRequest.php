<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

final class AttachLegalHoldActivityRequest extends LegalHoldRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return $this->closedBodyRules([
            'legal_hold' => ['required', 'uuid'],
            'activity_id' => ['required', 'integer', 'min:1'],
        ], ['activity_id']);
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->routeValidationData([
            'legal_hold' => $this->route('legalHold'),
        ]);
    }
}
