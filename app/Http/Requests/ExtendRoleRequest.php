<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\TemporalRoleUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

class ExtendRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'valid_until' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User|string|null $routeUser */
            $routeUser = $this->route('user');
            /** @var string|null $roleName */
            $roleName = $this->route('role');

            $userId = match (true) {
                $routeUser instanceof User => $routeUser->getKey(),
                is_string($routeUser) => $routeUser,
                default => null,
            };

            if (! $userId || ! $roleName) {
                return;
            }

            // Find role by name
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                return;
            }

            // Find current assignment using role_id
            $assignment = TemporalRoleUser::where('role_id', $role->id)
                ->where('model_id', $userId)
                ->where('model_type', User::class)
                ->first();

            // Validate new valid_until is after current valid_until
            if ($assignment && $this->input('valid_until')) {
                $newValidUntil = Carbon::parse($this->string('valid_until')->toString());
                if ($assignment->valid_until && $newValidUntil->lessThanOrEqualTo($assignment->valid_until)) {
                    $validator->errors()->add(
                        'valid_until',
                        'New expiration date must be after the current expiration date.'
                    );
                }
            }
        });
    }

    /**
     * Get custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valid_until.required' => 'New expiration date is required.',
            'valid_until.after' => 'Expiration date must be in the future.',
            'reason.max' => 'Reason must not exceed 500 characters.',
        ];
    }
}
