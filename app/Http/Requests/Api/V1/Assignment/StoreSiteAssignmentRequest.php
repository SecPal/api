<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Assignment;

use App\Models\Site;
use App\Models\SiteAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for creating site assignments.
 *
 * Validates the creation of flexible user-to-site role assignments.
 * Allows any role name (tenant-specific terminology) and optional validity period.
 *
 * @see SiteAssignment
 * @see SecPal/api#315 Assignment API endpoints
 */
class StoreSiteAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled in the controller via policies.
     */
    public function authorize(): bool
    {
        /** @var Site|null $site */
        $site = $this->route('site');

        return $site !== null
            && ($this->user()?->can('create', [SiteAssignment::class, $site]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var int|null $tenantId */
        $tenantId = $this->integer('tenant_id') ?: $this->user()?->tenant_id;

        return [
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id')->where(function (\Illuminate\Database\Query\Builder $query) use ($tenantId): void {
                    $query->where('tenant_id', $tenantId ?? 0);
                }),
            ],
            'role' => ['required', 'string', 'max:100'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => 'user',
            'role' => 'role',
            'valid_from' => 'valid from date',
            'valid_until' => 'valid until date',
            'notes' => 'notes',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'The user field is required.',
            'user_id.uuid' => 'The user must be a valid UUID.',
            'user_id.exists' => 'The selected user does not exist.',
            'role.required' => 'The role field is required.',
            'role.max' => 'The role must not exceed 100 characters.',
            'valid_from.date' => 'The valid from date must be a valid date.',
            'valid_until.date' => 'The valid until date must be a valid date.',
            'valid_until.after_or_equal' => 'The valid until date must be on or after the valid from date.',
            'notes.max' => 'The notes must not exceed 1000 characters.',
        ];
    }
}
