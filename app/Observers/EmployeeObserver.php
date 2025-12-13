<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Observers;

use App\Mail\AccountDeactivatedMail;
use App\Mail\OnboardingInvitationMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Employee;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * EmployeeObserver handles automatic user account lifecycle and blind index computation.
 *
 * Responsibilities:
 * 1. Compute blind indexes for encrypted fields (first_name, last_name, date_of_birth)
 * 2. Create user accounts for pre_contract employees
 * 3. Activate/deactivate user accounts on status transitions
 * 4. Assign temporal roles based on contract dates
 * 5. Send lifecycle notification emails
 *
 * Status Transitions:
 * - creating: Generate blind indexes, create user account if pre_contract
 * - updating: Handle status changes (pre_contract → active → terminated)
 *
 * Error Handling:
 * - User creation failures are logged but don't block employee creation
 * - Email failures are logged but don't block status transitions
 * - Database operations use transactions for atomicity
 */
class EmployeeObserver
{
    /**
     * Handle the Employee "creating" event.
     *
     * 1. Compute blind indexes before initial save
     * Note: User account creation moved to "created" event to avoid FK conflicts
     */
    public function creating(Employee $employee): void
    {
        Log::debug('EmployeeObserver::creating fired', [
            'employee_id' => $employee->id,
            'status' => $employee->status,
            'tenant_id' => $employee->tenant_id,
        ]);

        $this->updateBlindIndexes($employee);
    }

    /**
     * Handle the Employee "created" event.
     *
     * Create user account for pre_contract employees after employee is persisted.
     */
    public function created(Employee $employee): void
    {
        Log::info('EmployeeObserver::created fired', [
            'employee_id' => $employee->id,
            'status' => $employee->status,
            'user_id' => $employee->user_id,
            'should_create_user' => $employee->status === Employee::STATUS_PRE_CONTRACT && ! $employee->user_id,
        ]);

        // Create user account for pre-contract employees
        if ($employee->status === Employee::STATUS_PRE_CONTRACT && ! $employee->user_id) {
            $this->createUserAccount($employee);
        }
    }

    /**
     * Handle the Employee "updating" event.
     *
     * 1. Recompute blind indexes if encrypted fields changed
     * 2. Handle status transitions for user account lifecycle
     */
    public function updating(Employee $employee): void
    {
        // Recompute indexes if encrypted fields are dirty
        if ($employee->isDirty(['first_name_enc', 'last_name_enc', 'date_of_birth_enc'])) {
            $this->updateBlindIndexes($employee);
        }

        // Handle status changes
        if ($employee->isDirty('status')) {
            $oldStatus = $employee->getOriginal('status');
            $newStatus = $employee->status;

            // Only process if both are strings
            if (is_string($oldStatus) && is_string($newStatus)) {
                $this->handleStatusTransition($employee, $oldStatus, $newStatus);
            }
        }
    }

    /**
     * Handle status transitions for user account lifecycle.
     *
     * @param  string  $oldStatus  Previous status
     * @param  string  $newStatus  New status
     */
    private function handleStatusTransition(Employee $employee, string $oldStatus, string $newStatus): void
    {
        // Transition: pre_contract → active
        if ($oldStatus === Employee::STATUS_PRE_CONTRACT && $newStatus === Employee::STATUS_ACTIVE) {
            $this->activateUserAccount($employee);
        }

        // Transition: active → terminated
        if ($oldStatus === Employee::STATUS_ACTIVE && $newStatus === Employee::STATUS_TERMINATED) {
            $this->deactivateUserAccount($employee);
        }

        // Transition: pre_contract → terminated (contract cancelled before start)
        if ($oldStatus === Employee::STATUS_PRE_CONTRACT && $newStatus === Employee::STATUS_TERMINATED) {
            $this->deactivateUserAccount($employee);
        }

        // Transition: on_leave → terminated (contract ended while on leave)
        if ($oldStatus === Employee::STATUS_ON_LEAVE && $newStatus === Employee::STATUS_TERMINATED) {
            $this->deactivateUserAccount($employee);
        }

        // Transitions for on_leave (future: implement read-only permissions)
        if ($oldStatus === Employee::STATUS_ACTIVE && $newStatus === Employee::STATUS_ON_LEAVE) {
            $this->handleOnLeave($employee);
        }

        if ($oldStatus === Employee::STATUS_ON_LEAVE && $newStatus === Employee::STATUS_ACTIVE) {
            $this->restoreFromLeave($employee);
        }
    }

    /**
     * Create user account for pre-contract employee.
     *
     * Creates user with:
     * - Random password (user must reset via email link)
     * - Email not verified (must verify)
     * - Active status (can login to onboarding portal)
     * - No roles assigned (roles assigned on activation)
     *
     * Sends onboarding invitation email with password reset link.
     *
     * Error Handling:
     * - Failures are logged but don't block employee creation
     * - HR can manually retry via admin panel
     */
    private function createUserAccount(Employee $employee): void
    {
        try {
            DB::transaction(function () use ($employee) {
                // Check if user already exists with this email
                $existingUser = User::where('email', $employee->email)->first();

                if ($existingUser) {
                    // Reuse existing user
                    $user = $existingUser;
                    Log::info('Reusing existing user account for employee', [
                        'employee_id' => $employee->id,
                        'user_id' => $user->id,
                        'email' => $employee->email,
                    ]);
                } else {
                    // Create new user
                    $user = User::create([
                        'name' => $employee->first_name.' '.$employee->last_name,
                        'email' => $employee->email,
                        'password' => Hash::make(Str::random(32)), // Random password, user must reset
                        'email_verified_at' => null, // Must verify email
                    ]);

                    Log::info('Created new user account for employee', [
                        'employee_id' => $employee->id,
                        'user_id' => $user->id,
                    ]);
                }

                // Update employee without triggering observers again
                $employee->updateQuietly([
                    'user_id' => $user->id,
                    'user_account_active' => true,
                    'user_account_activated_at' => now(),
                    'onboarding_steps' => $employee->onboarding_steps ?? Employee::getDefaultOnboardingSteps(),
                    'onboarding_started_at' => $employee->onboarding_started_at ?? now(),
                ]);

                // Send onboarding invitation
                Mail::to($user->email)->queue(new OnboardingInvitationMail($employee, $user));
            });
        } catch (\Exception $e) {
            Log::error('User account creation failed for employee', [
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - allow employee record to save
            // HR can manually retry via admin panel
        }
    }

    /**
     * Activate user account and assign role (pre_contract → active).
     *
     * - Assigns Employee role with temporal dates (valid_from = contract_start_date)
     * - Updates user_account_active = true
     * - Sends welcome email
     *
     * Error Handling:
     * - Role assignment failures are logged but don't block activation
     * - Email failures are logged but don't block activation
     */
    private function activateUserAccount(Employee $employee): void
    {
        $user = $employee->user;

        if (! $user) {
            Log::warning('Cannot activate user account: user not found', [
                'employee_id' => $employee->id,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($employee, $user) {
                // Assign base role "Employee" with temporal dates
                $role = Role::where('name', 'Employee')->first();

                if ($role) {
                    DB::table('model_has_roles')->insert([
                        'role_id' => $role->id,
                        'model_type' => User::class,
                        'model_id' => $user->id,
                        'tenant_id' => $employee->tenant_id,
                        'valid_from' => $employee->contract_start_date,
                        'valid_until' => $employee->termination_date, // NULL if no termination date
                        'auto_revoke' => true,
                        'assigned_by' => null, // System-assigned
                        'reason' => 'Automatic role assignment on contract start',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    Log::error('Role "Employee" not found', [
                        'employee_id' => $employee->id,
                    ]);
                }

                // Update employee record without triggering observers again
                $employee->updateQuietly([
                    'user_account_active' => true,
                    'user_account_activated_at' => now(),
                ]);
            });

            // Send welcome email
            Mail::to($user->email)->queue(new WelcomeActiveMail($employee));
        } catch (\Exception $e) {
            Log::error('User account activation failed', [
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deactivate user account (active → terminated).
     *
     * - Revokes ALL roles
     * - Sets user_account_active = false
     * - Deletes all sessions
     * - Revokes all API tokens
     * - Sends deactivation notice
     */
    private function deactivateUserAccount(Employee $employee): void
    {
        $user = $employee->user;

        if (! $user) {
            return;
        }

        try {
            DB::transaction(function () use ($employee, $user) {
                // Revoke ALL roles
                $user->roles()->detach();

                // Deactivate account without triggering observers again
                $employee->updateQuietly([
                    'user_account_active' => false,
                    'user_account_deactivated_at' => now(),
                ]);

                // Delete all sessions
                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->delete();

                // Revoke all API tokens
                $user->tokens()->delete();
            });

            // Send deactivation notice
            Mail::to($user->email)->queue(new AccountDeactivatedMail($employee));
        } catch (\Exception $e) {
            Log::error('User account deactivation failed', [
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle on_leave status (future: limit permissions).
     *
     * Currently logs the change. Future implementation:
     * - Switch to read-only role
     * - Preserve role assignments for restoration
     */
    private function handleOnLeave(Employee $employee): void
    {
        Log::info('Employee on leave', [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
        ]);
        // Future: Implement read-only permissions
    }

    /**
     * Restore from on_leave status.
     *
     * Currently logs the change. Future implementation:
     * - Restore original role assignments
     */
    private function restoreFromLeave(Employee $employee): void
    {
        Log::info('Employee restored from leave', [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
        ]);
        // Future: Restore full permissions
    }

    /**
     * Update blind indexes for first_name, last_name, date_of_birth.
     *
     * During create: _enc fields contain plaintext (before Cast encryption)
     * During update: _enc fields contain JSON (after Cast encryption)
     */
    private function updateBlindIndexes(Employee $employee): void
    {
        $tenantKey = TenantKey::findOrFail($employee->tenant_id);

        // Helper to extract plaintext from _enc field (handles both plaintext and encrypted JSON)
        $getPlaintext = function (string $encField) use ($employee): ?string {
            $rawValue = $employee->getAttributes()[$encField] ?? null;

            if ($rawValue === null) {
                return null;
            }

            if (! is_string($rawValue)) {
                Log::warning("Unexpected non-string value in {$encField} during blind index computation", [
                    'employee_id' => $employee->id,
                    'type' => gettype($rawValue),
                ]);

                return null;
            }

            // Check if it's JSON (existing record) or plaintext (new record before cast)
            // Only treat as encrypted if JSON structure matches exactly {ciphertext, nonce}
            try {
                $decoded = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['ciphertext'], $decoded['nonce']) && count($decoded) === 2) {
                    // Encrypted JSON with exact structure - use accessor to decrypt
                    $field = preg_replace('/_(enc|encrypted)$/', '', $encField);
                    $decrypted = $employee->$field;

                    if (! is_string($decrypted)) {
                        Log::warning("Decryption returned non-string value in {$encField}", [
                            'employee_id' => $employee->id,
                            'type' => gettype($decrypted),
                        ]);

                        return null;
                    }

                    return $decrypted;
                }
            } catch (\JsonException $e) {
                // Not valid JSON, treat as plaintext (expected during create)
            }

            // Plaintext - use directly (happens during create before Cast encryption)
            return $rawValue;
        };

        // Compute first_name_idx
        $firstName = $getPlaintext('first_name_enc');
        if ($firstName !== null) {
            $rawIdx = $tenantKey->generateBlindIndex(mb_strtolower($firstName));
            $employee->first_name_idx = base64_encode($rawIdx);
        }

        // Compute last_name_idx
        $lastName = $getPlaintext('last_name_enc');
        if ($lastName !== null) {
            $rawIdx = $tenantKey->generateBlindIndex(mb_strtolower($lastName));
            $employee->last_name_idx = base64_encode($rawIdx);
        }

        // Compute date_of_birth_idx
        $dateOfBirth = $getPlaintext('date_of_birth_enc');
        if ($dateOfBirth !== null) {
            $rawIdx = $tenantKey->generateBlindIndex($dateOfBirth);
            $employee->date_of_birth_idx = base64_encode($rawIdx);
        }
    }
}
