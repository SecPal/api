<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

final class DetachLegalHoldActivityRequest extends LegalHoldRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStringInput('justification');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return $this->closedBodyRules([
            'legal_hold' => ['required', 'uuid'],
            'attachment' => ['required', 'uuid'],
            'justification' => ['required', 'string', 'min:1', 'max:2000'],
        ], ['justification']);
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->routeValidationData([
            'legal_hold' => $this->route('legalHold'),
            'attachment' => $this->route('attachment'),
        ]);
    }
}
