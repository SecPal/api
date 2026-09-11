<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentLocale;
use App\Http\Middleware\InjectTenantId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class WorkInstructionContentRequest extends FormRequest
{
    public const AUTHORITATIVE_CONTRACTS_SHA = '21c1ba03ed12c17a73a3d4298b1140b20de3237a';

    /** @var array<string, string> */
    public const OPERATION_IDS = [
        'GET v1/work-instruction-templates' => 'listWorkInstructionTemplates',
        'POST v1/work-instruction-templates' => 'createWorkInstructionTemplate',
        'GET v1/work-instruction-templates/{workInstructionTemplate}' => 'getWorkInstructionTemplate',
        'PUT v1/work-instruction-templates/{workInstructionTemplate}' => 'replaceWorkInstructionTemplateTranslations',
        'GET v1/standard-blocks' => 'listWorkInstructionStandardBlocks',
        'GET v1/standard-blocks/{standardBlock}' => 'getWorkInstructionStandardBlock',
    ];

    /** @var array<string, list<int>> */
    public const RESPONSE_STATUSES = [
        'GET v1/work-instruction-templates' => [200, 401, 403, 422, 429, 500],
        'POST v1/work-instruction-templates' => [201, 401, 403, 422, 429, 500],
        'GET v1/work-instruction-templates/{workInstructionTemplate}' => [200, 401, 403, 404, 422, 429, 500],
        'PUT v1/work-instruction-templates/{workInstructionTemplate}' => [200, 401, 403, 404, 422, 429, 500],
        'GET v1/standard-blocks' => [200, 401, 403, 422, 429, 500],
        'GET v1/standard-blocks/{standardBlock}' => [200, 401, 403, 404, 422, 429, 500],
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function requestedLocale(): ContentLocale
    {
        $locale = $this->validated('locale');
        if (is_string($locale)) {
            return ContentLocale::from($locale);
        }

        $resolved = app()->getLocale();

        return ContentLocale::tryFrom($resolved) ?? ContentLocale::English;
    }

    /** @return array<string, array<int, mixed>> */
    protected function localeRule(): array
    {
        return ['locale' => ['sometimes', Rule::enum(ContentLocale::class)]];
    }

    /** @return array<string, array<int, mixed>> */
    protected function translationRules(): array
    {
        return [
            'translations' => ['required', 'array:de,en', 'min:1', 'max:2'],
            'translations.de' => ['sometimes', 'required', 'array:title,body'],
            'translations.de.title' => ['required_with:translations.de', 'string', 'max:255', 'not_regex:/\A\s*\z/u'],
            'translations.de.body' => ['required_with:translations.de', 'string', 'not_regex:/\A\s*\z/u'],
            'translations.en' => ['sometimes', 'required', 'array:title,body'],
            'translations.en.title' => ['required_with:translations.en', 'string', 'max:255', 'not_regex:/\A\s*\z/u'],
            'translations.en.body' => ['required_with:translations.en', 'string', 'not_regex:/\A\s*\z/u'],
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
        foreach ($this->originalQueryKeys() as $key) {
            $validator->errors()->add($key, 'Query parameters are not accepted for content mutations.');
        }
    }

    /** @param list<string> $allowed */
    protected function rejectUnknownBodyKeys(Validator $validator, array $allowed): void
    {
        $keys = $this->attributes->get(InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE, []);
        if (! is_array($keys)) {
            return;
        }

        foreach ($keys as $key) {
            if ((is_int($key) || is_string($key)) && ! in_array((string) $key, $allowed, true)) {
                $validator->errors()->add((string) $key, 'This property is not accepted.');
            }
        }
    }

    /** @param list<string> $allowed */
    protected function rejectUnknownQueryKeys(Validator $validator, array $allowed): void
    {
        foreach ($this->originalQueryKeys() as $key) {
            if (! in_array($key, $allowed, true)) {
                $validator->errors()->add($key, 'This query parameter is not accepted.');
            }
        }
    }

    /** @return list<string> */
    private function originalQueryKeys(): array
    {
        $keys = $this->attributes->get(
            InjectTenantId::ORIGINAL_QUERY_KEYS_ATTRIBUTE,
            array_keys($this->query->all()),
        );

        if (! is_array($keys)) {
            return [];
        }

        $normalized = [];
        foreach ($keys as $key) {
            if (is_int($key) || is_string($key)) {
                $normalized[] = (string) $key;
            }
        }

        return $normalized;
    }
}
