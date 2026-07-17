<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Employee;
use App\Models\User;
use App\Policies\EmployeePolicy;
use App\Services\DomainAccessService;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

trait InteractsWithEmployeeDomainValidation
{
    private function validateEmployeeDomainAssignment(Validator $validator, ?Employee $employee = null): void
    {
        if ($validator->errors()->hasAny(['legal_entity_id', 'establishment_id', 'management_level'])) {
            return;
        }

        $legalEntityId = $this->input('legal_entity_id', $employee?->legal_entity_id);
        $establishmentId = $this->input('establishment_id', $employee?->establishment_id);

        if (! is_string($legalEntityId) || ! is_string($establishmentId)) {
            return;
        }

        /** @var User|null $user */
        $user = $this->user();
        $tenantId = $this->integer('tenant_id');
        if (! $user instanceof User || $tenantId < 1) {
            return;
        }

        $touchesDomain = $employee === null
            || $this->exists('legal_entity_id')
            || $this->exists('establishment_id');

        try {
            if ($touchesDomain) {
                app(DomainAccessService::class)->ensureEmployeeDomainWritable(
                    $user,
                    $tenantId,
                    $legalEntityId,
                    $establishmentId,
                );
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                if (! is_string($field) || ! is_array($messages)) {
                    continue;
                }

                foreach ($messages as $message) {
                    if (is_string($message)) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }

            return;
        }

        $defaultManagementLevel = $employee instanceof Employee ? $employee->management_level : 0;
        $managementLevel = $this->input('management_level', $defaultManagementLevel);
        $resolvedManagementLevel = is_numeric($managementLevel) ? (int) $managementLevel : 0;
        $policy = app(EmployeePolicy::class);
        $rankAllowed = $employee instanceof Employee
            ? $policy->canUpdateAtManagementLevel($user, $resolvedManagementLevel)
            : $policy->canCreateAtManagementLevel($user, $resolvedManagementLevel);

        if (! $rankAllowed) {
            $validator->errors()->add(
                'management_level',
                __('You may only manage employees whose management level remains assignable and viewable within your access scope.'),
            );
        }
    }
}
