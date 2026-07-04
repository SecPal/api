<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Support;

final class ResidentialAddressHistorySchema
{
    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'title' => 'Residential Address History',
            'description' => 'Provide your current residential address, the date since you have lived there, and earlier residences covering the last five years.',
            'type' => 'object',
            'properties' => [
                'current_address' => [
                    'type' => 'object',
                    'title' => 'Current Residential Address',
                    'properties' => [
                        'street' => ['type' => 'string', 'title' => 'Street', 'maxLength' => 255],
                        'house_number' => ['type' => 'string', 'title' => 'House Number', 'maxLength' => 50],
                        'postal_code' => ['type' => 'string', 'title' => 'Postal Code', 'maxLength' => 20],
                        'city' => ['type' => 'string', 'title' => 'City', 'maxLength' => 255],
                        'supplement' => ['type' => 'string', 'title' => 'Address Supplement', 'maxLength' => 255],
                        'country' => ['type' => 'string', 'title' => 'Country', 'pattern' => '^[A-Z]{2}$'],
                        'resided_from' => [
                            'type' => 'string',
                            'title' => 'Date You Started Living There',
                            'pattern' => '^\d{4}-\d{2}-\d{2}$',
                        ],
                    ],
                    'required' => ['street', 'house_number', 'postal_code', 'city', 'country', 'resided_from'],
                ],
                'previous_addresses' => [
                    'type' => 'array',
                    'title' => 'Previous Residences',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'street' => ['type' => 'string', 'title' => 'Street', 'maxLength' => 255],
                            'house_number' => ['type' => 'string', 'title' => 'House Number', 'maxLength' => 50],
                            'postal_code' => ['type' => 'string', 'title' => 'Postal Code', 'maxLength' => 20],
                            'city' => ['type' => 'string', 'title' => 'City', 'maxLength' => 255],
                            'supplement' => ['type' => 'string', 'title' => 'Address Supplement', 'maxLength' => 255],
                            'country' => ['type' => 'string', 'title' => 'Country', 'pattern' => '^[A-Z]{2}$'],
                            'resided_from' => [
                                'type' => 'string',
                                'title' => 'Resided From',
                                'pattern' => '^\d{4}-\d{2}-\d{2}$',
                            ],
                            'resided_until' => [
                                'type' => 'string',
                                'title' => 'Resided Until',
                                'pattern' => '^\d{4}-\d{2}-\d{2}$',
                            ],
                        ],
                        'required' => ['street', 'house_number', 'postal_code', 'city', 'country', 'resided_from', 'resided_until'],
                    ],
                ],
            ],
            'required' => ['current_address'],
        ];
    }
}
