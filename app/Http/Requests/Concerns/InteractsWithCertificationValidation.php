<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Support\Carbon;

trait InteractsWithCertificationValidation
{
    /**
     * @return array<string, array<int, mixed>>
     */
    private function certificationValidationRules(bool $patch = false): array
    {
        return [
            'firearms_license_number' => $this->prefixedRules($patch, ['nullable', 'string', 'max:255']),
            'firearms_license_expiry' => $this->prefixedRules($patch, ['nullable', 'date']),
            'firearms_license_issued_by' => $this->prefixedRules($patch, ['nullable', 'string', 'max:255']),
            'first_aid_cert_number' => $this->prefixedRules($patch, ['nullable', 'string', 'max:255']),
            'first_aid_cert_date' => $this->prefixedRules($patch, ['nullable', 'date']),
            'first_aid_cert_expiry' => $this->prefixedRules($patch, ['nullable', 'date', 'after:first_aid_cert_date']),
            'fire_safety_cert_date' => $this->prefixedRules($patch, ['nullable', 'date']),
            'fire_safety_cert_expiry' => $this->prefixedRules($patch, ['nullable', 'date', 'after:fire_safety_cert_date']),
            'evacuation_cert_date' => $this->prefixedRules($patch, ['nullable', 'date']),
            'evacuation_cert_expiry' => $this->prefixedRules($patch, ['nullable', 'date', 'after:evacuation_cert_date']),
            'additional_certifications' => $this->prefixedRules($patch, ['nullable', 'array']),
            'additional_certifications.*' => ['array'],
            'additional_certifications.*.name' => ['required', 'string', 'max:255'],
            'additional_certifications.*.number' => ['nullable', 'string', 'max:255'],
            'additional_certifications.*.issued_date' => ['nullable', 'date'],
            'additional_certifications.*.expiry_date' => [
                'nullable',
                'date',
                $this->additionalCertificationExpiryAfterIssueDateRule(),
            ],
            'additional_certifications.*.issuer' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function certificationValidationMessages(): array
    {
        return [
            'first_aid_cert_expiry.after' => 'Erste-Hilfe-Zertifikat-Ablaufdatum muss nach dem Ausstellungsdatum liegen.',
            'fire_safety_cert_expiry.after' => 'Brandschutz-Zertifikat-Ablaufdatum muss nach dem Ausstellungsdatum liegen.',
            'evacuation_cert_expiry.after' => 'Evakuierungs-Zertifikat-Ablaufdatum muss nach dem Ausstellungsdatum liegen.',
            'additional_certifications.*.name.required' => 'Zusätzliche Zertifizierungen benötigen einen Namen.',
        ];
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return array<int, mixed>
     */
    private function prefixedRules(bool $patch, array $rules): array
    {
        return $patch ? ['sometimes', ...$rules] : $rules;
    }

    private function additionalCertificationExpiryAfterIssueDateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $issuedAttribute = preg_replace('/\.expiry_date$/', '.issued_date', $attribute);
            if (! is_string($issuedAttribute)) {
                return;
            }

            $issuedDate = data_get($this->all(), $issuedAttribute);
            if (! is_string($issuedDate) || $issuedDate === '') {
                return;
            }

            try {
                $expiry = Carbon::parse($value);
                $issued = Carbon::parse($issuedDate);
            } catch (\Throwable) {
                return;
            }

            if ($expiry->lessThanOrEqualTo($issued)) {
                $fail('Ablaufdatum zusätzlicher Zertifizierungen muss nach dem Ausstellungsdatum liegen.');
            }
        };
    }
}
