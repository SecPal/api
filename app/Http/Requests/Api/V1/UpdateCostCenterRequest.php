<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCostCenterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var \App\Models\Site|null $site */
        $site = $this->route('site');
        /** @var \App\Models\CostCenter|null $costCenter */
        $costCenter = $this->route('costCenter');

        /** @var string|null $siteId */
        $siteId = $site?->id;
        /** @var string|null $costCenterId */
        $costCenterId = $costCenter?->id;

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('cost_centers', 'code')
                    ->where('site_id', (string) $siteId)
                    ->ignore($costCenterId)
                    ->whereNull('deleted_at'),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'activity_type' => [
                'nullable',
                'string',
                'max:100',
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
