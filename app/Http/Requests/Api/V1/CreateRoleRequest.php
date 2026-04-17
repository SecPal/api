<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Requests\Api\V1;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Query\Builder;
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
        $rolesTable = config('permission.table_names.roles');
        if (! is_string($rolesTable) || $rolesTable === '') {
            $rolesTable = 'roles';
        }

        $teamColumn = config('permission.column_names.team_foreign_key');
        if (! is_string($teamColumn) || $teamColumn === '') {
            $teamColumn = 'tenant_id';
        }

        /** @var User $user */
        $user = $this->user();
        $tenantId = $this->input($teamColumn, $user->tenant_id);
        if (is_numeric($tenantId)) {
            $tenantId = (int) $tenantId;
        } else {
            $tenantId = null;
        }

        return Rule::unique($rolesTable, 'name')->where(function (Builder $query) use ($teamColumn, $tenantId): void {
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
