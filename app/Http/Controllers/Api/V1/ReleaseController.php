<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PublicApiRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReleaseController extends Controller
{
    public function show(PublicApiRelease $publicApiRelease): JsonResponse
    {
        $missingFields = $publicApiRelease->missingFields();

        if ($missingFields !== []) {
            return response()->json([
                'message' => __('Public API release metadata is incomplete for this deployment.'),
                'code' => 'RELEASE_STATE_INVALID',
                'details' => [
                    'missing_fields' => $missingFields,
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'data' => $publicApiRelease->responseData(),
        ]);
    }
}
