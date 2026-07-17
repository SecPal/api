<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a dedicated pre-contract employee + login for onboarding UI demos.
 *
 * Does not use {@see WithoutModelEvents}: employee blind indexes and related
 * model behaviour must run normally.
 */
class OnboardingDemoUserSeeder extends Seeder
{
    public const DEMO_EMPLOYEE_NUMBER = 'EMP-SEED-ONBOARD-01';

    public function run(): void
    {
        $this->runWithModelEvents(function (): void {
            $tenant = TenantKey::query()->firstOrFail();
            $tenantId = $tenant->id;

            $holding = OrganizationalUnit::query()
                ->where('tenant_id', $tenantId)
                ->where('type', 'holding')
                ->where('name', 'SecPal Holding')
                ->firstOrFail();

            $user = User::query()->firstOrCreate(
                ['email' => 'onboarding@example.com'],
                [
                    'name' => 'John Doe',
                    'password' => Hash::make('password'),
                    'tenant_id' => $tenantId,
                ],
            );

            $user->forceFill([
                'name' => 'John Doe',
                'password' => Hash::make('password'),
                'tenant_id' => $tenantId,
                'email_verified_at' => now(),
            ])->save();

            $employee = Employee::query()
                ->where('tenant_id', $tenantId)
                ->where('email', 'onboarding@example.com')
                ->first();

            $legalEntity = LegalEntity::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => 'SecPal Demo GmbH'],
                ['is_active' => true],
            );
            $establishment = Establishment::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $legalEntity->id,
                    'name' => 'SecPal Demo Berlin',
                ],
                ['is_active' => true],
            );

            $payload = [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'legal_entity_id' => $legalEntity->id,
                'establishment_id' => $establishment->id,
                'employee_number' => self::DEMO_EMPLOYEE_NUMBER,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'date_of_birth' => '1990-01-01',
                'email' => 'onboarding@example.com',
                'gender' => 'male',
                'birth_city' => 'Berlin',
                'birth_country' => 'DE',
                'nationalities' => ['DE'],
                'status' => Employee::STATUS_PRE_CONTRACT,
                'position' => 'Sicherheitsmitarbeiter',
                'management_level' => 0,
                'hire_date' => '2028-05-01',
                'contract_start_date' => '2028-05-01',
                'contract_type' => 'full_time',
                'weekly_hours' => 40.00,
                'monthly_hours' => 173.00,
                'work_permit_type' => Employee::WORK_PERMIT_TYPE_NONE,
                'residence_permit_type' => 'none',
                'bwr_status' => 'not_registered',
                'user_account_active' => true,
                'user_account_activated_at' => now(),
                'onboarding_completed' => false,
                'onboarding_steps' => Employee::getDefaultOnboardingSteps(),
                'onboarding_started_at' => now(),
                // Logged-in pre-contract users must be past `invited` so draft submissions
                // can transition the workflow to `in_progress` (see Employee::ALLOWED_WORKFLOW_TRANSITIONS).
                'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED,
            ];

            if ($employee === null) {
                Employee::query()->create($payload);
            } else {
                $employee->fill($payload);
                $employee->save();
            }

            $this->command->info('Onboarding demo user: onboarding@example.com / password (pre-contract, SecPal Holding).');
        });
    }

    private function runWithModelEvents(callable $callback): void
    {
        $dispatcher = Model::getEventDispatcher();

        Model::setEventDispatcher(app('events'));

        try {
            $callback();
        } finally {
            if ($dispatcher === null) {
                Model::unsetEventDispatcher();

                return;
            }

            Model::setEventDispatcher($dispatcher);
        }
    }
}
