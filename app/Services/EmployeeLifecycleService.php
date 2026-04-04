<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Mail\AccountDeactivatedMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;

class EmployeeLifecycleService
{
    /**
     * Activate a pre-contract employee and provision their runtime access atomically.
     */
    public function activate(Employee $employee): Employee
    {
        $activatedEmployee = DB::transaction(function () use ($employee): Employee {
            $employee = $this->refreshEmployee($employee);

            $user = $employee->user;

            if (! $user instanceof User) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot activate: employee has no linked user account'),
                ]);
            }

            $role = Role::where('name', 'Employee')->where('guard_name', 'sanctum')->first();

            if (! $role instanceof Role) {
                throw new RuntimeException('Role "Employee" not found.');
            }

            $employee->update([
                'status' => Employee::STATUS_ACTIVE,
                'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACTIVE,
                'user_account_active' => true,
                'user_account_activated_at' => now(),
            ]);

            $this->assignEmployeeRole($employee, $user, $role);
            $user->unsetRelation('roles');

            return $this->refreshEmployee($employee);
        });

        $this->queueWelcomeMail($activatedEmployee);

        return $activatedEmployee;
    }

    /**
     * Terminate an employee and revoke their runtime access atomically.
     */
    public function terminate(Employee $employee): Employee
    {
        $terminatedEmployee = DB::transaction(function () use ($employee): Employee {
            $employee = $this->refreshEmployee($employee);

            /** @var User|null $user */
            $user = $employee->user;

            $employee->update([
                'status' => Employee::STATUS_TERMINATED,
                'user_account_active' => false,
                'user_account_deactivated_at' => now(),
            ]);

            if ($user instanceof User) {
                DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->where('model_id', $user->id)
                    ->where('tenant_id', $employee->tenant_id)
                    ->delete();
                $user->tokens()->delete();

                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->delete();

                $user->unsetRelation('roles');
            }

            return $this->refreshEmployee($employee);
        });

        $this->queueDeactivationMail($terminatedEmployee);

        return $terminatedEmployee;
    }

    private function assignEmployeeRole(Employee $employee, User $user, Role $role): void
    {
        $assignmentExists = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('role_id', $role->id)
            ->where('tenant_id', $employee->tenant_id)
            ->exists();

        if ($assignmentExists) {
            return;
        }

        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $employee->tenant_id,
            'valid_from' => $employee->contract_start_date,
            'valid_until' => $employee->termination_date,
            'auto_revoke' => true,
            'assigned_by' => null,
            'reason' => 'Automatic role assignment on contract start',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function queueWelcomeMail(Employee $employee): void
    {
        $user = $employee->user;

        if (! $user instanceof User) {
            return;
        }

        try {
            Mail::to($user->email)->queue(new WelcomeActiveMail($employee));
        } catch (\Throwable $throwable) {
            Log::error('User account activation mail dispatch failed', [
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function queueDeactivationMail(Employee $employee): void
    {
        $user = $employee->user;

        if (! $user instanceof User) {
            return;
        }

        try {
            Mail::to($user->email)->queue(new AccountDeactivatedMail($employee));
        } catch (\Throwable $throwable) {
            Log::error('User account deactivation mail dispatch failed', [
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function refreshEmployee(Employee $employee): Employee
    {
        /** @var Employee|null $refreshedEmployee */
        $refreshedEmployee = $employee->fresh();

        if (! $refreshedEmployee instanceof Employee) {
            $employee->load(['user']);

            return $employee;
        }

        $refreshedEmployee->load(['user']);

        return $refreshedEmployee;
    }
}
