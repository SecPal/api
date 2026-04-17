<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Requests\Api\V1;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class CreateRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by Gate in controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->roleNameUniqueRule()],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        $fail('The permission must be a valid string.');

                        return;
                    }
                    if (! Permission::where('name', $value)->exists()) {
                        $fail("The permission {$value} does not exist.");
                    }
                },
            ],
        ];
    }

    private function roleNameUniqueRule(): Unique
    {
        $rolesTable = (string) config('permission.table_names.roles', 'roles');
        $teamColumn = (string) config('permission.column_names.team_foreign_key', 'tenant_id');
        $tenantId = $this->user()?->tenant_id ?? $this->input($teamColumn);

        return Rule::unique($rolesTable, 'name')->where(function ($query) use ($teamColumn, $tenantId): void {
            $query->where('guard_name', 'sanctum');

            if ($tenantId === null) {
                $query->whereNull($teamColumn);

                return;
            }

            $query->where($teamColumn, $tenantId);
        });
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Role name is required.',
            'name.unique' => 'A role with this name already exists.',
            'permissions.*.required' => 'Each permission must be a valid string.',
        ];
    }
}
