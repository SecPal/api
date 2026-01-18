<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * OnboardingSchemaLocalizationService localizes JSON Schema forms.
 *
 * Translates schema titles, descriptions, field labels, enum values,
 * and other user-facing text based on the specified locale.
 */
class OnboardingSchemaLocalizationService
{
    /**
     * Localize a JSON Schema based on locale.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function localizeSchema(array $schema, string $locale): array
    {
        // Set application locale for trans() calls
        $originalLocale = App::getLocale();
        App::setLocale($locale);

        try {
            $localized = $this->localizeSchemaRecursive($schema, []);

            return $localized;
        } finally {
            // Restore original locale
            App::setLocale($originalLocale);
        }
    }

    /**
     * Recursively localize schema properties.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $path  Path to current property (for translation keys)
     * @return array<string, mixed>
     */
    private function localizeSchemaRecursive(array $schema, array $path): array
    {
        $localized = $schema;

        // Localize title
        if (isset($schema['title'])) {
            $localized['title'] = $this->translateSchemaProperty($schema['title'], $path, 'title');
        }

        // Localize description
        if (isset($schema['description'])) {
            $localized['description'] = $this->translateSchemaProperty($schema['description'], $path, 'description');
        }

        // Localize enum names (human-readable labels for enum values)
        if (isset($schema['enum']) && isset($schema['enumNames'])) {
            // Try to get localized enum values as pipe-separated string
            // Build key with proper path (empty path means root level, which needs the path from context)
            $pathString = count($path) > 0 ? implode('.', $path) : '';
            $enumKey = $pathString ? "onboarding_schemas.{$pathString}.enum" : 'onboarding_schemas.enum';

            Log::info('Enum translation attempt', [
                'path' => $path,
                'pathString' => $pathString,
                'enumKey' => $enumKey,
                'originalEnumNames' => $schema['enumNames']
            ]);

            $translated = trans($enumKey);

            if ($translated !== $enumKey && is_string($translated)) {
                // Split pipe-separated string into array
                $localized['enumNames'] = explode('|', $translated);
                Log::info('Enum translated via pipe-separated string', [
                    'key' => $enumKey,
                    'translated' => $translated,
                    'result' => $localized['enumNames']
                ]);
            } else {
                // Fallback: translate each enum name individually
                $localized['enumNames'] = array_map(function ($enumName) use ($path) {
                    return $this->translateSchemaProperty($enumName, $path, 'enum');
                }, $schema['enumNames']);
                Log::info('Enum translated individually (fallback)', [
                    'key' => $enumKey,
                    'translated' => $translated,
                    'result' => $localized['enumNames']
                ]);
            }
        }

        // Localize object properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $localized['properties'] = [];
            foreach ($schema['properties'] as $propName => $propSchema) {
                // Build the property-specific path for translation lookups
                $propPath = array_merge($path, [$propName]);

                $localized['properties'][$propName] = $this->localizeSchemaRecursive(
                    $propSchema,
                    $propPath
                );
            }
        }

        // Localize array items
        if (isset($schema['items']) && is_array($schema['items'])) {
            $localized['items'] = $this->localizeSchemaRecursive($schema['items'], array_merge($path, ['items']));
        }

        // Localize oneOf/anyOf/allOf schemas
        foreach (['oneOf', 'anyOf', 'allOf'] as $combiningKeyword) {
            if (isset($schema[$combiningKeyword]) && is_array($schema[$combiningKeyword])) {
                $localized[$combiningKeyword] = array_map(
                    fn ($subSchema) => $this->localizeSchemaRecursive($subSchema, $path),
                    $schema[$combiningKeyword]
                );
            }
        }

        return $localized;
    }

    /**
     * Translate a schema property using Laravel's translation system.
     *
     * @param  array<int, string>  $path  Path to property (e.g., ['gender'] or ['address', 'city'])
     */
    private function translateSchemaProperty(string $value, array $path, string $propertyType): string
    {
        // Build translation key: onboarding_schemas.{path}.{propertyType}
        // Example: onboarding_schemas.gender.title => "Geschlecht"
        $key = 'onboarding_schemas.'.implode('.', array_merge($path, [$propertyType]));

        $translated = trans($key);

        // If translation not found (returns key), return original value
        return $translated === $key ? $value : $translated;
    }
}

