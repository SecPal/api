<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\OnboardingFormTemplate;
use Illuminate\Validation\ValidationException;
use JsonException as JsonEncodeException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates onboarding form payloads against the template JSON Schema.
 *
 * Runs when the submission status is `submitted`; drafts only receive request-level array validation.
 */
final class OnboardingFormDataSchemaValidationService
{
    /**
     * @param  array<string, mixed>  $formData
     *
     * @throws ValidationException
     */
    public function assertMatchesTemplate(
        OnboardingFormTemplate $template,
        array $formData,
        bool $forSubmittedStatus,
    ): void {
        if (! $forSubmittedStatus) {
            return;
        }

        $schema = $template->form_schema ?? [];
        if (! is_array($schema)) {
            throw ValidationException::withMessages([
                'form_data' => [__('The form template schema is invalid.')],
            ]);
        }

        if (! $template->is_required && $this->isSemanticallyEmpty($schema, $formData)) {
            return;
        }

        if (($schema['type'] ?? null) !== 'object') {
            throw ValidationException::withMessages([
                'form_data' => [__('The form template schema must declare a JSON object root.')],
            ]);
        }

        try {
            $this->assertJsonSchemaValid($schema, $formData);
        } catch (JsonEncodeException) {
            throw ValidationException::withMessages([
                'form_data' => [__('The submitted form data could not be validated.')],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $formData
     *
     * @throws JsonEncodeException
     */
    private function assertJsonSchemaValid(array $schema, array $formData): void
    {
        $validator = new Validator(null, 100, false);
        $schemaObject = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false);
        assert($schemaObject instanceof \stdClass);

        $dataObject = $formData === []
            ? new \stdClass
            : json_decode(json_encode($formData, JSON_THROW_ON_ERROR), false);

        $result = $validator->validate($dataObject, $schemaObject);

        if ($result->isValid()) {
            return;
        }

        $rootError = $result->error();
        if ($rootError === null) {
            return;
        }

        $formatter = new ErrorFormatter;

        /** @var array<string, array<int, string>> $keyed */
        $keyed = $formatter->formatKeyed($rootError);

        $messages = [];
        foreach ($keyed as $pointer => $msgs) {
            $field = $this->jsonPointerToFieldKey($pointer);
            $messages[$field] ??= [];
            foreach ($msgs as $msg) {
                $messages[$field][] = $msg;
            }
        }

        throw ValidationException::withMessages($messages);
    }

    private function jsonPointerToFieldKey(string $pointer): string
    {
        $trimmed = trim($pointer, '/');
        if ($trimmed === '') {
            return 'form_data';
        }

        $parts = explode('/', $trimmed);

        return $parts[0] ?? 'form_data';
    }

    /**
     * Mirrors optional-step semantics in the onboarding wizard: if every schema field is empty,
     * we do not enforce JSON Schema {@code required} / patterns so optional templates can be
     * submitted with an empty payload (same as “all fields empty” on the client).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $formData
     */
    private function isSemanticallyEmpty(array $schema, array $formData): bool
    {
        if (($schema['type'] ?? null) !== 'object') {
            return $formData === [];
        }

        $properties = $schema['properties'] ?? [];
        if (! is_array($properties)) {
            return $formData === [];
        }

        foreach ($properties as $name => $property) {
            if (! is_array($property)) {
                continue;
            }

            /** @var array<string, mixed> $property */
            if (! $this->isPropertySemanticallyEmpty($property, $formData[$name] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $property
     */
    private function isPropertySemanticallyEmpty(array $property, mixed $value): bool
    {
        $type = $property['type'] ?? 'string';

        return match ($type) {
            'string' => (is_string($value) ? trim($value) : '') === '',
            'integer', 'number' => $value === null || $value === '',
            'boolean' => $value === null || $value === '',
            'array' => ! is_array($value) || count($value) === 0,
            default => $value === null || $value === '',
        };
    }
}
