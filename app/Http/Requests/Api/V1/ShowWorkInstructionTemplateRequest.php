<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;

final class ShowWorkInstructionTemplateRequest extends WorkInstructionContentRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['work_instruction_template' => ['required', 'uuid'], ...$this->localeRule()];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->routeValidationData(['work_instruction_template' => $this->route('workInstructionTemplate')]);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectUnknownQueryKeys($validator, ['locale'])];
    }
}
