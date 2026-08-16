<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Employee;
use App\Models\OnboardingFormTemplate;
use App\Support\AddressHistoryLookback;
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
    private const CONTRACT_START_DATE_FIELD = 'contract_start_date';

    private const RESIDENCE_PERMIT_EXPIRY_FIELD = 'residence_permit_expiry';

    private const RESIDENCE_PERMIT_TITLE_FIELD = 'residence_permit_title';

    private const RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD = 'residence_permit_employment_allowed';

    private const RESIDENCE_PERMIT_UNLIMITED_FIELD = 'residence_permit_unlimited';

    private const RESIDENTIAL_ADDRESS_HISTORY_TEMPLATE_KEY = 'residential_address_history';

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
        ?Employee $employee = null,
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

        if ($template->template_key === self::RESIDENTIAL_ADDRESS_HISTORY_TEMPLATE_KEY) {
            $this->assertResidentialAddressHistoryValid($formData);

            return;
        }

        if (! $this->schemaDefinesResidencePermitFields($schema)) {
            return;
        }

        if ($this->schemaDefinesField($schema, 'nationalities')) {
            $contractStartDate = $this->resolveContractStartDate($formData, $employee);
            $this->assertResidencePermitRequirementsForNationalities(
                $formData,
                $contractStartDate
            );

            if (! $this->requiresResidenceTitleQuestion($formData)) {
                return;
            }

            $this->assertResidencePermitExpiryNotInPast(
                $formData,
                $contractStartDate,
                true
            );

            if ($this->canAskEmploymentForResidenceTitle($formData, $contractStartDate)) {
                $this->assertResidencePermitEmploymentAllowed($formData);
            }

            return;
        }

        // Backwards compatibility for templates that still include residence permit fields
        // but not the nationality gating field.
        $this->assertResidencePermitExpiryNotInPast($formData, null, false);
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

        foreach (array_keys($formData) as $name) {
            if (! array_key_exists($name, $properties)) {
                return false;
            }
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
    private function assertResidencePermitExpiryNotInPast(
        array $formData,
        ?string $contractStartDate,
        bool $enforceContractStartRule = true,
    ): void {
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

        if (! $enforceContractStartRule || $contractStartDate === null) {
            return;
        }

        if ($expiryDateString <= $contractStartDate) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EXPIRY_FIELD => [__('The residence title must remain valid after your contract start date.')],
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
    private function assertResidencePermitRequirementsForNationalities(
        array $formData,
        ?string $contractStartDate,
    ): void {
        if (! $this->requiresResidenceTitleQuestion($formData)) {
            return;
        }

        $residenceTitle = $this->normalizedNonEmptyString($formData[self::RESIDENCE_PERMIT_TITLE_FIELD] ?? null);
        if ($residenceTitle === null) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_TITLE_FIELD => [__('The residence title selection is required.')],
            ]);
        }

        if ($this->isResidencePermitUnlimited($formData)) {
            if (! $this->canAskEmploymentForResidenceTitle($formData, $contractStartDate)) {
                return;
            }

            $employmentAllowed = $this->normalizedNonEmptyString($formData[self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD] ?? null);
            if ($employmentAllowed === null) {
                throw ValidationException::withMessages([
                    self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD => [__('The employment authorization decision is required.')],
                ]);
            }

            return;
        }

        $expiryDate = $this->normalizedNonEmptyString($formData[self::RESIDENCE_PERMIT_EXPIRY_FIELD] ?? null);
        if ($expiryDate === null) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EXPIRY_FIELD => [__('The residence title expiry date is required.')],
            ]);
        }

        if (! $this->canAskEmploymentForResidenceTitle($formData, $contractStartDate)) {
            return;
        }

        $employmentAllowed = $this->normalizedNonEmptyString($formData[self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD] ?? null);
        if ($employmentAllowed === null) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD => [__('The employment authorization decision is required.')],
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

    private function isValidCalendarDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTime && $date->format('Y-m-d') === $value;
    }

    private function normalizedNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function resolveContractStartDate(
        array $formData,
        ?Employee $employee,
    ): ?string {
        $formValue = $this->normalizedNonEmptyString(
            $formData[self::CONTRACT_START_DATE_FIELD] ?? null
        );
        if ($formValue !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $formValue) === 1) {
            return $formValue;
        }

        $employeeStartDate = $employee?->contract_start_date?->toDateString();
        if (is_string($employeeStartDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $employeeStartDate) === 1) {
            return $employeeStartDate;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function canAskEmploymentForResidenceTitle(
        array $formData,
        ?string $contractStartDate,
    ): bool {
        $residenceTitle = $this->normalizedNonEmptyString(
            $formData[self::RESIDENCE_PERMIT_TITLE_FIELD] ?? null
        );
        if ($residenceTitle === null) {
            return false;
        }

        if ($this->isResidencePermitUnlimited($formData)) {
            return true;
        }

        if ($contractStartDate === null) {
            return false;
        }

        $expiryDate = $this->normalizedNonEmptyString(
            $formData[self::RESIDENCE_PERMIT_EXPIRY_FIELD] ?? null
        );
        if (
            $expiryDate === null
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate) !== 1
            || $expiryDate <= now()->toDateString()
        ) {
            return false;
        }

        return $expiryDate > $contractStartDate;
    }

    /**
     * @param  array<string, mixed>  $formData
     *
     * @throws ValidationException
     */
    private function assertResidentialAddressHistoryValid(array $formData): void
    {
        $messages = [];

        $currentAddress = $formData['current_address'] ?? null;
        if (! is_array($currentAddress)) {
            throw ValidationException::withMessages([
                'current_address' => [$this->validationMessageString('Current Residential Address: This field is required.')],
            ]);
        }

        $this->appendResidentialAddressMessages(
            $messages,
            'current_address',
            'Current Residential Address',
            $this->stringKeyedAddressRow($currentAddress),
            requireUntilDate: false,
        );

        $previousAddresses = $formData['previous_addresses'] ?? [];
        if (! is_array($previousAddresses)) {
            $messages['previous_addresses'][] = $this->validationMessageString(
                'Previous Residences: Invalid address list.',
            );
        } else {
            foreach ($previousAddresses as $index => $address) {
                if (! is_array($address)) {
                    $messages["previous_addresses.{$index}"][] = $this->validationMessageString(
                        'Previous Residences: Invalid address entry.',
                    );

                    continue;
                }

                $this->appendResidentialAddressMessages(
                    $messages,
                    "previous_addresses.{$index}",
                    $this->validationMessageString('Previous residence #:number', ['number' => $index + 1]),
                    $this->stringKeyedAddressRow($address),
                    requireUntilDate: true,
                );
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }

        // Require at least one prior residence when the current address alone does not cover the
        // regulatory lookback window (see {@see AddressHistoryLookback} and {@see BewacherregisterExportService}).
        $residedFrom = $this->normalizedNonEmptyString($currentAddress['resided_from'] ?? null);
        $windowStart = now()->startOfDay()->copy()->subYears(AddressHistoryLookback::YEARS)->toDateString();
        if (
            $residedFrom !== null
            && $this->isValidCalendarDate($residedFrom)
            && $residedFrom > $windowStart
            && (! is_array($previousAddresses) || $previousAddresses === [])
        ) {
            throw ValidationException::withMessages([
                'previous_addresses' => [$this->validationMessageString(
                    'Please provide all previous residences covering the last five years.',
                )],
            ]);
        }
    }

    /**
     * @param  array<string, array<int, string>>  $messages
     * @param  array<string, mixed>  $address
     */
    private function appendResidentialAddressMessages(
        array &$messages,
        string $path,
        string $label,
        array $address,
        bool $requireUntilDate,
    ): void {
        foreach ([
            'street' => $this->validationMessageString('Street'),
            'house_number' => $this->validationMessageString('House Number'),
            'postal_code' => $this->validationMessageString('Postal Code'),
            'city' => $this->validationMessageString('City'),
            'country' => $this->validationMessageString('Country'),
            'resided_from' => $requireUntilDate
                ? $this->validationMessageString('Resided From')
                : $this->validationMessageString('Date You Started Living There'),
        ] as $field => $fieldLabel) {
            if ($this->normalizedNonEmptyString($address[$field] ?? null) !== null) {
                continue;
            }

            $messages["{$path}.{$field}"][] = $this->validationMessageString(
                ':label: :field is required.',
                ['label' => $label, 'field' => $fieldLabel],
            );
        }

        $country = $this->normalizedNonEmptyString($address['country'] ?? null);
        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $messages["{$path}.country"][] = $this->validationMessageString(
                ':label: Use a two-letter country code in uppercase, for example DE.',
                ['label' => $label],
            );
        }

        $from = $this->normalizedNonEmptyString($address['resided_from'] ?? null);
        if ($from !== null && ! $this->isValidCalendarDate($from)) {
            $messages["{$path}.resided_from"][] = $this->validationMessageString(
                ':label: Please use the required format (YYYY-MM-DD).',
                ['label' => $label],
            );
        }

        $until = $this->normalizedNonEmptyString($address['resided_until'] ?? null);
        if ($requireUntilDate) {
            if ($until === null) {
                $messages["{$path}.resided_until"][] = $this->validationMessageString(
                    ':label: Resided Until is required.',
                    ['label' => $label],
                );
            } elseif (! $this->isValidCalendarDate($until)) {
                $messages["{$path}.resided_until"][] = $this->validationMessageString(
                    ':label: Please use the required format (YYYY-MM-DD).',
                    ['label' => $label],
                );
            }
        }

        if (
            $from !== null
            && $until !== null
            && $this->isValidCalendarDate($from)
            && $this->isValidCalendarDate($until)
            && $from > $until
        ) {
            $messages["{$path}.resided_until"][] = $this->validationMessageString(
                ':label: The end date must be on or after the start date.',
                ['label' => $label],
            );
        }
    }

    /**
     * @param  array<mixed, mixed>  $row
     * @return array<string, mixed>
     */
    private function stringKeyedAddressRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $replace
     */
    private function validationMessageString(string $key, array $replace = []): string
    {
        $resolved = __($key, $replace);

        return is_string($resolved) ? $resolved : $key;
    }
}
