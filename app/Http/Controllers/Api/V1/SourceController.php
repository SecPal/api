<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PublicSourceOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SourceController extends Controller
{
    public function show(PublicSourceOffer $publicSourceOffer): JsonResponse
    {
        $missingFields = $publicSourceOffer->missingFields();

        if ($missingFields !== []) {
            return response()->json([
                'message' => __('Public source offer configuration is incomplete for this deployment.'),
                'code' => 'SOURCE_STATE_INVALID',
                'details' => [
                    'missing_fields' => $missingFields,
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $sourceUrl = $publicSourceOffer->canonicalSourceUrl();

        if ($sourceUrl === null) {
            return response()->json([
                'message' => __('Public source offer configuration is incomplete for this deployment.'),
                'code' => 'SOURCE_STATE_INVALID',
                'details' => [
                    'missing_fields' => ['app_url'],
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'data' => $publicSourceOffer->sourceResponseData($sourceUrl),
        ]);
    }
}
