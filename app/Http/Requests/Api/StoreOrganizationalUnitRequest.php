<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api;

use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Form request for storing a new organizational unit.
 *
 * Enforces hierarchy validation (Issue #301):
 * - Child type rank must be > parent type rank
 * - Hierarchy: Holding(1) → Company(2) → Region(3) → Branch(4) → Division(5) → Department(6) → Custom(7)
 */
class StoreOrganizationalUnitRequest extends FormRequest
{
    /**
     * Hierarchy ranking for organizational unit types.
     * Lower number = higher in hierarchy.
     */
    private const TYPE_HIERARCHY = [
        'holding' => 1,
        'company' => 2,
        'region' => 3,
        'branch' => 4,
        'division' => 5,
        'department' => 6,
        'custom' => 7,
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var mixed $parentId */
        $parentId = $this->input('parent_id');

        if (! is_string($parentId) || $parentId === '' || ! Str::isUuid($parentId)) {
            return $this->user()?->can('createRoot', OrganizationalUnit::class) ?? false;
        }

        $parent = OrganizationalUnit::query()->find($parentId);

        if ($parent === null) {
            return $this->user()?->can('createRoot', OrganizationalUnit::class) ?? false;
        }

        return $this->user()?->can('create', $parent) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => [
                'required',
                'string',
                Rule::in(['holding', 'company', 'region', 'branch', 'division', 'department', 'custom']),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value)) {
                        $this->validateHierarchy($value, $fail);
                    }
                },
            ],
            'custom_type_name' => ['nullable', 'string', 'max:255', 'required_if:type,custom'],
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
            'parent_id' => ['nullable', 'uuid', Rule::exists(OrganizationalUnit::class, 'id')],
        ];
    }

    /**
     * Validate hierarchy constraints.
     *
     * Rule: Child rank must be > parent rank (lower in hierarchy).
     * Root units (no parent) are always allowed.
     *
     * @param  string  $childType  The type being created
     * @param  \Closure  $fail  Validation failure callback
     */
    private function validateHierarchy(string $childType, \Closure $fail): void
    {
        /** @var string|null $parentId */
        $parentId = $this->input('parent_id');

        // Root units have no constraints
        if ($parentId === null) {
            return;
        }

        // Find parent unit
        $parent = OrganizationalUnit::find($parentId);
        if ($parent === null) {
            // Parent doesn't exist - will be caught by exists rule
            return;
        }

        $parentRank = self::TYPE_HIERARCHY[$parent->type] ?? 999;
        $childRank = self::TYPE_HIERARCHY[$childType] ?? 999;

        // Child rank must be > parent rank (no same-level nesting allowed)
        if ($childRank <= $parentRank) {
            $fail("Type '{$childType}' cannot be created under type '{$parent->type}'. Hierarchy violation: {$childType} (rank {$childRank}) must be lower in hierarchy than {$parent->type} (rank {$parentRank}). Same-level nesting is not allowed.");
        }
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_type_name.required_if' => 'The custom type name is required when type is "custom".',
            'parent_id.exists' => 'The selected parent organizational unit does not exist.',
        ];
    }
}
