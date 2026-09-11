<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Middleware\InjectTenantId;
use Illuminate\Validation\Validator;

final class UpdateWorkInstructionRequest extends WorkInstructionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = $this->contentRules([]);
        unset($rules['instruction_number']);

        return array_merge(
            ['work_instruction' => ['required', 'uuid']],
            $rules,
        );
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->mutationValidationData(['work_instruction' => $this->route('workInstruction')]);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->rejectMutationQueryParameters($validator);
            $this->rejectUnknownBodyKeys($validator, self::UPDATE_FIELDS);
            $originalBodyKeys = $this->attributes->get(InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE, []);

            if (is_array($originalBodyKeys) && $originalBodyKeys === []) {
                $validator->errors()->add('work_instruction', 'At least one Work Instruction property is required.');
            }
        }];
    }
}
