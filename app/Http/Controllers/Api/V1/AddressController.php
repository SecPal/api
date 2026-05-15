<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Address\IndexAddressLocalityRequest;
use App\Http\Requests\Api\V1\Address\IndexAddressStreetRequest;
use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use App\Services\AddressData\AddressSuggestionService;
use Illuminate\Http\JsonResponse;

class AddressController extends Controller
{
    public function streets(IndexAddressStreetRequest $request, AddressSuggestionService $suggestions): JsonResponse
    {
        $country = 'DE';
        $active = $suggestions->activeImport($country);
        if ($active === null) {
            return response()->json([
                'message' => __('Address reference data is not available yet.'),
                'code' => 'address_data_unavailable',
            ], 503);
        }

        $limit = $request->limitResolved();

        $rows = $suggestions->suggestStreets(
            $country,
            $request->filled('name') ? $request->string('name')->toString() : null,
            $request->filled('postal_code') ? $request->string('postal_code')->toString() : null,
            $request->filled('locality') ? $request->string('locality')->toString() : null,
            $limit,
            $active,
        );

        return response()->json([
            'data' => $rows->map(fn (AddressStreet $row): array => [
                'name' => $row->name,
                'postal_code' => $row->postal_code,
                'locality' => $row->locality,
                'borough' => $row->borough,
                'suburb' => $row->suburb,
                'regional_key' => $row->regional_key,
            ])->values()->all(),
            'meta' => $this->metaPayload($active),
        ]);
    }

    public function localities(IndexAddressLocalityRequest $request, AddressSuggestionService $suggestions): JsonResponse
    {
        $country = 'DE';
        $active = $suggestions->activeImport($country);
        if ($active === null) {
            return response()->json([
                'message' => __('Address reference data is not available yet.'),
                'code' => 'address_data_unavailable',
            ], 503);
        }

        $limit = $request->limitResolved();

        $rows = $suggestions->suggestLocalities(
            $country,
            $request->filled('postal_code') ? $request->string('postal_code')->toString() : null,
            $request->filled('locality') ? $request->string('locality')->toString() : null,
            $limit,
            $active,
        );

        return response()->json([
            'data' => $rows->values()->all(),
            'meta' => $this->metaPayload($active),
        ]);
    }

    public function status(AddressSuggestionService $suggestions): JsonResponse
    {
        $country = 'DE';
        $active = $suggestions->activeImport($country);
        if ($active === null) {
            return response()->json([
                'message' => __('Address reference data is not available yet.'),
                'code' => 'address_data_unavailable',
            ], 503);
        }

        return response()->json([
            'data' => [
                'country' => $country,
                'row_count' => $active->row_count,
                'imported_at' => $active->activated_at?->toIso8601String(),
            ],
            'meta' => $this->metaPayload($active),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metaPayload(AddressDataImport $active): array
    {
        return [
            'source' => $active->source_name,
            'license' => $active->license,
            'attribution' => $active->attribution,
            'imported_at' => $active->activated_at?->toIso8601String(),
            'version_hash' => $active->source_sha256,
        ];
    }
}
