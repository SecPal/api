<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Assignment;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;

class IndexSiteAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $site = $this->route('site');

        return $site instanceof Site
            ? ($this->user()?->can('view', $site) ?? false)
            : false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['nullable', 'string', 'max:100'],
            'active_only' => ['nullable', 'boolean'],
        ];
    }
}
