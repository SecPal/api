<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\CustomerEstablishment;
use Illuminate\Foundation\Http\FormRequest;

final class IndexCustomerEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', CustomerEstablishment::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'uuid'],
            'establishment_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
