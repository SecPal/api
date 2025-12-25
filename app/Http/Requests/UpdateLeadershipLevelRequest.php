<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * UpdateLeadershipLevelRequest validates Leadership Level update requests.
 *
 * Supports PATCH semantics (all fields optional).
 * Enforces uniqueness constraints when updating rank or name.
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/424 Issue #424: Leadership Levels Backend API
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md ADR-009
 */
class UpdateLeadershipLevelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled by LeadershipLevelPolicy.
     * User must have 'leadership_level.update' permission (guard: sanctum).
     */
    public function authorize(): bool
    {
        return true; // Policy handles authorization
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * All fields are optional (PATCH semantics).
     * Enforces uniqueness constraints when provided, excluding current record.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::guard('sanctum')->user();
        $tenantId = $user?->tenant_id;

        /** @var \App\Models\LeadershipLevel $leadershipLevel */
        $leadershipLevel = $this->route('leadership_level');
        $leadershipLevelId = $leadershipLevel->id;

        return [
            'rank' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:255',
                Rule::unique('leadership_levels', 'rank')
                    ->where('tenant_id', $tenantId)
                    ->ignore($leadershipLevelId)
                    ->whereNull('deleted_at'),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('leadership_levels', 'name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($leadershipLevelId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * Provides user-friendly error messages for validation failures.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rank.required' => __('Leadership level rank is required'),
            'rank.integer' => __('Rank must be a number'),
            'rank.min' => __('Rank must be at least 1'),
            'rank.max' => __('Rank must not exceed 255'),
            'rank.unique' => __('This rank is already assigned to another leadership level in your organization'),
            'name.required' => __('Leadership level name is required'),
            'name.max' => __('Name must not exceed 255 characters'),
            'name.unique' => __('This name is already used by another leadership level in your organization'),
            'description.max' => __('Description must not exceed 1000 characters'),
            'color.regex' => __('Color must be a valid hex color code (e.g., #FF5733 or #F57)'),
            'is_active.required' => __('Active status must be specified'),
            'is_active.boolean' => __('Active status must be true or false'),
        ];
    }
}
