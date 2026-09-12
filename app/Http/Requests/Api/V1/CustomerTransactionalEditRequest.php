<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Exceptions\CustomerTransactionalEditException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

final class CustomerTransactionalEditRequest
{
    public const AUTHORITATIVE_CONTRACTS_SHA = '6639eca8a09a1a563a861724e4fb230c93d8a565';

    /** @var list<string> */
    public const CUSTOMER_FIELDS = [
        'legal_entity_id',
        'vat_id',
        'name',
        'billing_address',
        'is_active',
    ];

    /** @var list<string> */
    public const ASSIGNMENT_FIELDS = [
        'customer_id',
        'establishment_id',
        'contact_name',
        'phone',
        'email',
        'comments',
    ];

    /** @var list<string> */
    private const ADDRESS_FIELDS = [
        'street',
        'city',
        'postal_code',
        'country',
        'latitude',
        'longitude',
    ];

    /**
     * Parse and validate the syntactic request contract after If-Match succeeds.
     *
     * @return array{
     *   customer: array<string, mixed>,
     *   customer_establishments: list<array<string, mixed>>
     * }
     */
    public function validate(string $content, string $pathCustomerId): array
    {
        try {
            $document = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw CustomerTransactionalEditException::badRequest();
        }

        if (! $document instanceof stdClass) {
            $this->fail(['request' => ['The request body is invalid.']]);
        }

        $root = get_object_vars($document);
        if (array_diff(array_keys($root), ['customer', 'customer_establishments']) !== []) {
            $this->fail(['request' => ['The request body is invalid.']]);
        }

        $structuralErrors = [];
        if (! property_exists($document, 'customer') || ! $document->customer instanceof stdClass) {
            $structuralErrors['customer'] = ['The customer payload is invalid.'];
        } else {
            $customer = get_object_vars($document->customer);
            if (array_diff(array_keys($customer), self::CUSTOMER_FIELDS) !== []) {
                $structuralErrors['customer'] = ['The customer payload is invalid.'];
            }

            if (($document->customer->billing_address ?? null) instanceof stdClass) {
                $address = get_object_vars($document->customer->billing_address);
                if (array_diff(array_keys($address), self::ADDRESS_FIELDS) !== []) {
                    $structuralErrors['customer'] = ['The customer payload is invalid.'];
                }
            }
        }

        if (! property_exists($document, 'customer_establishments')
            || ! is_array($document->customer_establishments)) {
            $structuralErrors['customer_establishments'] = ['The assignment collection is invalid.'];
        } else {
            foreach ($document->customer_establishments as $index => $item) {
                if (! $item instanceof stdClass) {
                    $structuralErrors["customer_establishments.{$index}"] = ['The assignment item is invalid.'];

                    continue;
                }

                if (array_diff(array_keys(get_object_vars($item)), self::ASSIGNMENT_FIELDS) !== []) {
                    $structuralErrors["customer_establishments.{$index}"] = ['The assignment item is invalid.'];
                }
            }
        }

        if ($structuralErrors !== []) {
            $this->fail($structuralErrors);
        }

        /** @var array{customer: array<string, mixed>, customer_establishments: list<array<string, mixed>>} $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $validator = Validator::make($payload, [
            'customer' => ['array'],
            'customer.legal_entity_id' => ['sometimes', 'uuid'],
            'customer.vat_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'customer.name' => ['sometimes', 'string', 'max:255'],
            'customer.billing_address' => [
                'sometimes',
                'array:street,city,postal_code,country,latitude,longitude',
            ],
            'customer.billing_address.street' => ['required_with:customer.billing_address', 'string', 'max:255'],
            'customer.billing_address.city' => ['required_with:customer.billing_address', 'string', 'max:100'],
            'customer.billing_address.postal_code' => ['required_with:customer.billing_address', 'string', 'max:20'],
            'customer.billing_address.country' => ['required_with:customer.billing_address', 'string', 'size:2'],
            'customer.billing_address.latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'customer.billing_address.longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'customer.is_active' => ['sometimes', 'boolean'],
            'customer_establishments' => ['array'],
            'customer_establishments.*' => ['array:customer_id,establishment_id,contact_name,phone,email,comments'],
            'customer_establishments.*.customer_id' => ['required', 'uuid'],
            'customer_establishments.*.establishment_id' => ['required', 'uuid'],
            'customer_establishments.*.contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_establishments.*.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer_establishments.*.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer_establishments.*.comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->keys() as $field) {
                $errors[$field] = [$this->neutralMessage($field)];
            }
            $this->fail($errors);
        }

        $identityErrors = [];
        $seenEstablishments = [];
        foreach ($payload['customer_establishments'] as $index => $assignment) {
            if (($assignment['customer_id'] ?? null) !== $pathCustomerId) {
                $identityErrors["customer_establishments.{$index}.customer_id"] = [
                    'The selected customer is invalid.',
                ];
            }

            $establishmentId = $assignment['establishment_id'] ?? null;
            if (! is_string($establishmentId)) {
                throw new \LogicException('Validated establishment_id must be a string.');
            }

            if (isset($seenEstablishments[$establishmentId])) {
                $identityErrors['customer_establishments'] = [
                    'Each establishment may be assigned at most once.',
                ];
            }
            $seenEstablishments[$establishmentId] = true;
        }

        if ($identityErrors !== []) {
            $this->fail($identityErrors);
        }

        return $payload;
    }

    private function neutralMessage(string $field): string
    {
        if (preg_match('/^customer_establishments\.\d+\.customer_id$/', $field) === 1) {
            return 'The selected customer is invalid.';
        }

        if (preg_match('/^customer_establishments\.\d+\.establishment_id$/', $field) === 1) {
            return 'The selected establishment is invalid.';
        }

        if (preg_match('/^customer_establishments\.\d+\.(contact_name|phone|email|comments)$/', $field) === 1) {
            return 'The contact field is invalid.';
        }

        if (str_starts_with($field, 'customer_establishments.')) {
            $parts = explode('.', $field);

            return count($parts) === 2
                ? 'The assignment item is invalid.'
                : 'The selected establishment is invalid.';
        }

        return 'The customer field is invalid.';
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return never
     */
    private function fail(array $errors): void
    {
        throw ValidationException::withMessages($errors);
    }
}
