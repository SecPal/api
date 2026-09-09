<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

final class StoreLegalHoldRequest extends LegalHoldRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStringInput('case_reference');
        $this->trimStringInput('justification');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return $this->closedBodyRules([
            'case_reference' => ['required', 'string', 'min:1', 'max:64'],
            'justification' => ['required', 'string', 'min:1', 'max:2000'],
        ], ['case_reference', 'justification']);
    }
}
