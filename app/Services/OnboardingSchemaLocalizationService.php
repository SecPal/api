<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\OnboardingFormTemplate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class OnboardingSchemaLocalizationService
{
    public const DEFAULT_LOCALE = 'en';

    public const SUPPORTED_LOCALES = ['en', 'de'];

    /**
     * @return array{name: string, description: ?string, form_schema: array<string, mixed>}
     */
    public function localizeTemplate(OnboardingFormTemplate $template, string $locale): array
    {
        $normalizedLocale = $this->normalizeLocale($locale);
        $templateKey = ($template->template_key !== null && $template->template_key !== '')
            ? $template->template_key
            : Str::snake($template->name);
        $schema = is_array($template->form_schema) ? $template->form_schema : [];

        return [
            'name' => $this->translate(
                "onboarding_schemas.templates.{$templateKey}.name",
                $template->name,
                $normalizedLocale,
            ),
            'description' => is_string($template->description)
                ? $this->translate(
                    "onboarding_schemas.templates.{$templateKey}.description",
                    $template->description,
                    $normalizedLocale,
                )
                : $template->description,
            'form_schema' => $this->localizeSchema($schema, $templateKey, $normalizedLocale),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $fieldPath
     * @return array<string, mixed>
     */
    private function localizeSchema(array $schema, string $templateKey, string $locale, array $fieldPath = []): array
    {
        $localized = $schema;
        $translationBase = $this->translationBase($templateKey, $fieldPath);

        if (isset($localized['title']) && is_string($localized['title'])) {
            $localized['title'] = $this->translate("{$translationBase}.title", $localized['title'], $locale);
        }

        if (isset($localized['description']) && is_string($localized['description'])) {
            $localized['description'] = $this->translate("{$translationBase}.description", $localized['description'], $locale);
        }

        $enumNames = $this->localizeEnumNames($localized['enum'] ?? null, $translationBase, $locale);
        if ($enumNames !== null) {
            $localized['enumNames'] = $enumNames;
        }

        if (isset($localized['properties']) && is_array($localized['properties'])) {
            foreach ($localized['properties'] as $propertyKey => $propertySchema) {
                if (! is_string($propertyKey) || ! is_array($propertySchema)) {
                    continue;
                }

                $normalizedPropertySchema = $this->normalizeSchemaArray($propertySchema);
                if ($normalizedPropertySchema === null) {
                    continue;
                }

                $localized['properties'][$propertyKey] = $this->localizeSchema(
                    $normalizedPropertySchema,
                    $templateKey,
                    $locale,
                    [...$fieldPath, $propertyKey],
                );
            }
        }

        if (isset($localized['items']) && is_array($localized['items'])) {
            $normalizedItemsSchema = $this->normalizeSchemaArray($localized['items']);

            if ($normalizedItemsSchema !== null) {
                $localized['items'] = $this->localizeSchema(
                    $normalizedItemsSchema,
                    $templateKey,
                    $locale,
                    $fieldPath,
                );
            }
        }

        foreach (['oneOf', 'anyOf', 'allOf'] as $compositeKey) {
            if (! isset($localized[$compositeKey]) || ! is_array($localized[$compositeKey])) {
                continue;
            }

            foreach ($localized[$compositeKey] as $index => $subSchema) {
                if (! is_array($subSchema)) {
                    continue;
                }

                $normalizedSubSchema = $this->normalizeSchemaArray($subSchema);
                if ($normalizedSubSchema === null) {
                    continue;
                }

                $localized[$compositeKey][$index] = $this->localizeSchema(
                    $normalizedSubSchema,
                    $templateKey,
                    $locale,
                    $fieldPath,
                );
            }
        }

        return $localized;
    }

    private function normalizeLocale(string $locale): string
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }

    /**
     * @param  array<mixed, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function normalizeSchemaArray(array $schema): ?array
    {
        if (array_is_list($schema)) {
            return null;
        }

        $normalized = [];

        foreach ($schema as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $fieldPath
     */
    private function translationBase(string $templateKey, array $fieldPath): string
    {
        if ($fieldPath === []) {
            return "onboarding_schemas.templates.{$templateKey}.schema";
        }

        return 'onboarding_schemas.templates.'.$templateKey.'.fields.'.implode('.', $fieldPath);
    }

    private function translate(string $key, string $fallback, string $locale): string
    {
        $translated = Lang::get($key, [], $locale);

        if (! is_string($translated) || $translated === $key) {
            return $fallback;
        }

        return $translated;
    }

    /**
     * @return list<string>|null
     */
    private function localizeEnumNames(mixed $enumValues, string $translationBase, string $locale): ?array
    {
        if (! is_array($enumValues)) {
            return null;
        }

        $localizedValues = [];
        $hasTranslation = false;

        foreach ($enumValues as $enumValue) {
            if (! is_string($enumValue)) {
                return null;
            }

            $translated = $this->translate("{$translationBase}.enum.{$enumValue}", $enumValue, $locale);
            $localizedValues[] = $translated;
            $hasTranslation = $hasTranslation || $translated !== $enumValue;
        }

        return $hasTranslation ? $localizedValues : null;
    }
}
