<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\OnboardingFormTemplate;
use Illuminate\Support\Carbon;
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
    private const RESIDENCE_PERMIT_EXPIRY_FIELD = 'residence_permit_expiry';

    private const RESIDENCE_PERMIT_TITLE_FIELD = 'residence_permit_title';

    private const RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD = 'residence_permit_employment_allowed';

    private const RESIDENCE_PERMIT_UNLIMITED_FIELD = 'residence_permit_unlimited';

    /**
     * EEA + Switzerland (frontend parity for work-permit exemption).
     *
     * @var list<string>
     */
    private const RESIDENCE_TITLE_EXEMPT_COUNTRY_CODES = [
        'AT',
        'BE',
        'BG',
        'CH',
        'CY',
        'CZ',
        'DE',
        'DK',
        'EE',
        'ES',
        'FI',
        'FR',
        'GR',
        'HR',
        'HU',
        'IE',
        'IS',
        'IT',
        'LI',
        'LT',
        'LU',
        'LV',
        'MT',
        'NL',
        'NO',
        'PL',
        'PT',
        'RO',
        'SE',
        'SI',
        'SK',
    ];

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

        if (! $this->schemaDefinesResidencePermitFields($schema)) {
            return;
        }

        if ($this->schemaDefinesField($schema, 'nationalities')) {
            $this->assertResidencePermitRequirementsForNationalities($formData);

            if (! $this->requiresResidenceTitleQuestion($formData)) {
                return;
            }
        }

        $this->assertResidencePermitExpiryNotInPast($formData);
        $this->assertResidencePermitEmploymentAllowed($formData);
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
            'string' => $value === null || (is_string($value) && trim($value) === ''),
            'integer', 'number' => $value === null || $value === '',
            'boolean' => $value === null || $value === '',
            'array' => $value === null || (is_array($value) && count($value) === 0),
            default => $value === null || $value === '',
        };
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function assertResidencePermitExpiryNotInPast(array $formData): void
    {
        if ($this->isResidencePermitUnlimited($formData)) {
            return;
        }

        $rawExpiry = $formData[self::RESIDENCE_PERMIT_EXPIRY_FIELD] ?? null;
        if (! is_string($rawExpiry) || trim($rawExpiry) === '') {
            return;
        }

        $expiryDateString = trim($rawExpiry);
        try {
            $expiryDate = Carbon::createFromFormat('Y-m-d', $expiryDateString);
        } catch (\Throwable) {
            $expiryDate = false;
        }

        if (! $expiryDate || $expiryDate->format('Y-m-d') !== $expiryDateString) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EXPIRY_FIELD => [__('Please enter a valid date in YYYY-MM-DD format.')],
            ]);
        }

        if ($expiryDate->startOfDay()->lt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EXPIRY_FIELD => [__('The residence title expiry date cannot be in the past.')],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function assertResidencePermitEmploymentAllowed(array $formData): void
    {
        $residenceTitle = $formData[self::RESIDENCE_PERMIT_TITLE_FIELD] ?? null;
        if (! is_string($residenceTitle) || trim($residenceTitle) === '') {
            return;
        }

        $employmentAllowed = $this->normalizedNonEmptyString(
            $formData[self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD] ?? null
        );

        if ($employmentAllowed === null) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD => [__('The employment authorization decision is required.')],
            ]);
        }

        $employmentAllowed = strtolower($employmentAllowed);

        if (! in_array($employmentAllowed, ['yes', 'no'], true)) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD => [__('The employment authorization decision must be either yes or no.')],
            ]);
        }

        if ($employmentAllowed === 'no') {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD => [__('A valid residence title without employment authorization cannot be accepted. Please contact HR.')],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function assertResidencePermitRequirementsForNationalities(array $formData): void
    {
        if (! $this->requiresResidenceTitleQuestion($formData)) {
            return;
        }

        $residenceTitle = $this->normalizedNonEmptyString($formData[self::RESIDENCE_PERMIT_TITLE_FIELD] ?? null);
        if ($residenceTitle === null) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_TITLE_FIELD => [__('The residence title selection is required.')],
            ]);
        }

        $employmentAllowed = $this->normalizedNonEmptyString($formData[self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD] ?? null);
        if ($employmentAllowed === null) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD => [__('The employment authorization decision is required.')],
            ]);
        }

        if ($this->isResidencePermitUnlimited($formData)) {
            return;
        }

        $expiryDate = $this->normalizedNonEmptyString($formData[self::RESIDENCE_PERMIT_EXPIRY_FIELD] ?? null);
        if ($expiryDate === null) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EXPIRY_FIELD => [__('The residence title expiry date is required.')],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function requiresResidenceTitleQuestion(array $formData): bool
    {
        $nationalities = $this->normalizedNationalityCodes($formData['nationalities'] ?? null);
        if ($nationalities === []) {
            return false;
        }

        foreach ($nationalities as $code) {
            if (! in_array($code, self::RESIDENCE_TITLE_EXEMPT_COUNTRY_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function normalizedNationalityCodes(mixed $nationalities): array
    {
        if (! is_array($nationalities)) {
            return [];
        }

        $codes = [];

        foreach ($nationalities as $entry) {
            if (! is_string($entry) && ! is_int($entry)) {
                continue;
            }

            $normalized = strtoupper(trim((string) $entry));
            if (preg_match('/^[A-Z]{2}$/', $normalized) !== 1) {
                continue;
            }

            $codes[] = $normalized;
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function schemaDefinesResidencePermitFields(array $schema): bool
    {
        foreach ([
            self::RESIDENCE_PERMIT_TITLE_FIELD,
            self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD,
            self::RESIDENCE_PERMIT_UNLIMITED_FIELD,
            self::RESIDENCE_PERMIT_EXPIRY_FIELD,
        ] as $field) {
            if ($this->schemaDefinesField($schema, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function schemaDefinesField(array $schema, string $field): bool
    {
        $properties = $schema['properties'] ?? null;

        return is_array($properties) && array_key_exists($field, $properties);
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function isResidencePermitUnlimited(array $formData): bool
    {
        $rawValue = $formData[self::RESIDENCE_PERMIT_UNLIMITED_FIELD] ?? null;

        if (is_bool($rawValue)) {
            return $rawValue;
        }

        if (is_int($rawValue)) {
            return $rawValue === 1;
        }

        if (! is_string($rawValue)) {
            return false;
        }

        return in_array(strtolower(trim($rawValue)), ['1', 'true', 'yes'], true);
    }

    private function normalizedNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
