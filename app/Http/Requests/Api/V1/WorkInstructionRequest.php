<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentLocale;
use App\Http\Middleware\InjectTenantId;
use App\Models\WorkInstruction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class WorkInstructionRequest extends FormRequest
{
    public const AUTHORITATIVE_CONTRACTS_SHA = '074d23437437dad5457d3a2418d814ddd6baee03';

    /** @var list<string> */
    public const CREATE_FIELDS = WorkInstruction::CREATE_FIELDS;

    /** @var list<string> */
    public const UPDATE_FIELDS = WorkInstruction::MUTABLE_BUSINESS_FIELDS;

    /** @var array<string, string> */
    public const OPERATION_IDS = [
        'GET v1/work-instructions' => 'listWorkInstructions',
        'POST v1/work-instructions' => 'createWorkInstruction',
        'GET v1/work-instructions/{workInstruction}' => 'getWorkInstruction',
        'PATCH v1/work-instructions/{workInstruction}' => 'updateWorkInstruction',
        'POST v1/work-instructions/{workInstruction}/submit-for-review' => 'submitWorkInstructionForReview',
        'POST v1/work-instructions/{workInstruction}/publish' => 'publishWorkInstruction',
        'POST v1/work-instructions/{workInstruction}/archive' => 'archiveWorkInstruction',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  list<string>  $required
     * @return array<string, array<int, mixed>>
     */
    protected function contentRules(array $required): array
    {
        $presence = static fn (string $field): string => in_array($field, $required, true)
            ? 'required'
            : 'sometimes';
        $requiredWhenPresent = static fn (string $field): array => in_array($field, $required, true)
            ? []
            : ['required'];

        return [
            'instruction_number' => [$presence('instruction_number'), ...$requiredWhenPresent('instruction_number'), 'string', 'max:64', 'not_regex:/\A\s*\z/u'],
            'title' => [$presence('title'), ...$requiredWhenPresent('title'), 'string', 'max:255', 'not_regex:/\A\s*\z/u'],
            'body' => [$presence('body'), ...$requiredWhenPresent('body'), 'string', 'not_regex:/\A\s*\z/u'],
            'locale' => [$presence('locale'), ...$requiredWhenPresent('locale'), Rule::enum(ContentLocale::class)],
        ];
    }

    /** @param array<string, mixed> $routeParameters
     * @return array<string, mixed>
     */
    protected function routeValidationData(array $routeParameters): array
    {
        /** @var array<string, mixed> $data */
        $data = parent::validationData();

        return array_merge($data, $routeParameters);
    }

    /** @param array<string, mixed> $routeParameters
     * @return array<string, mixed>
     */
    protected function mutationValidationData(array $routeParameters = []): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->getInputSource()->all();

        return array_merge($data, $routeParameters);
    }

    protected function rejectMutationQueryParameters(Validator $validator): void
    {
        $originalQueryKeys = $this->attributes->get(
            InjectTenantId::ORIGINAL_QUERY_KEYS_ATTRIBUTE,
            array_keys($this->query->all()),
        );

        if (! is_array($originalQueryKeys)) {
            return;
        }

        foreach ($originalQueryKeys as $key) {
            if (is_int($key) || is_string($key)) {
                $validator->errors()->add((string) $key, 'Query parameters are not accepted for Work Instruction mutations.');
            }
        }
    }

    /** @param list<string> $allowedBodyKeys */
    protected function rejectUnknownBodyKeys(Validator $validator, array $allowedBodyKeys): void
    {
        $originalBodyKeys = $this->attributes->get(InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE, []);

        if (! is_array($originalBodyKeys)) {
            return;
        }

        foreach ($originalBodyKeys as $key) {
            if ((is_int($key) || is_string($key)) && ! in_array((string) $key, $allowedBodyKeys, true)) {
                $validator->errors()->add((string) $key, 'This property is not accepted.');
            }
        }
    }

    /** @param list<string> $allowedQueryKeys */
    protected function rejectUnknownQueryKeys(Validator $validator, array $allowedQueryKeys): void
    {
        $originalQueryKeys = $this->attributes->get(
            InjectTenantId::ORIGINAL_QUERY_KEYS_ATTRIBUTE,
            array_keys($this->query->all()),
        );

        if (! is_array($originalQueryKeys)) {
            return;
        }

        foreach ($originalQueryKeys as $key) {
            if ((is_int($key) || is_string($key)) && ! in_array((string) $key, $allowedQueryKeys, true)) {
                $validator->errors()->add((string) $key, 'This query parameter is not accepted.');
            }
        }
    }
}
