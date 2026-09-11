<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;

final class WorkInstructionLifecycleActionRequest extends WorkInstructionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['work_instruction' => ['required', 'uuid']];
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
            $this->rejectUnknownBodyKeys($validator, []);

            if (trim($this->getContent()) !== '') {
                $validator->errors()->add('body', 'A request body is not accepted for Work Instruction lifecycle actions.');
            }
        }];
    }
}
