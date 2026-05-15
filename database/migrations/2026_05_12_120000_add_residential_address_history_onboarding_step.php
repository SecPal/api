<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Support\ResidentialAddressHistorySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RESIDENTIAL_ADDRESS_HISTORY_TEMPLATE_ID = '6c1f6d83-40ff-4fb0-96b8-e0f8d9aa2d4e';

    public function up(): void
    {
        $now = Carbon::now();

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('sort_order', '>=', 2)
            ->where(function (Builder $query): void {
                $query->whereNull('template_key')
                    ->orWhere('template_key', '!=', 'residential_address_history');
            })
            ->increment('sort_order');

        $existingTemplateId = DB::table('onboarding_form_templates')
            ->where('template_key', 'residential_address_history')
            ->whereNull('tenant_id')
            ->value('id');

        $payload = [
            'name' => 'Residential Address History',
            'description' => 'Current residential address and previous residences from the last five years.',
            'form_schema' => json_encode($this->residentialAddressHistorySchema(), JSON_THROW_ON_ERROR),
            'is_required' => true,
            'is_system_template' => true,
            'sort_order' => 2,
            'updated_at' => $now,
        ];

        if (is_string($existingTemplateId) && $existingTemplateId !== '') {
            // Preserve pre-existing global residential templates as-is so
            // rollback stays non-destructive for legacy installs.
        } else {
            DB::table('onboarding_form_templates')->insert([
                'id' => self::RESIDENTIAL_ADDRESS_HISTORY_TEMPLATE_ID,
                'tenant_id' => null,
                'template_key' => 'residential_address_history',
                'created_at' => $now,
                ...$payload,
            ]);
        }

        $this->addResidentialAddressHistoryStepToEmployees($now);
    }

    public function down(): void
    {
        $now = Carbon::now();

        $insertedTemplateIds = DB::table('onboarding_form_templates')
            ->where('id', self::RESIDENTIAL_ADDRESS_HISTORY_TEMPLATE_ID)
            ->pluck('id')
            ->all();

        if (
            $insertedTemplateIds !== []
            && DB::table('onboarding_form_submissions')->whereIn('form_template_id', $insertedTemplateIds)->exists()
        ) {
            throw new RuntimeException('Cannot rollback residential address history migration after submissions exist.');
        }

        if ($insertedTemplateIds !== []) {
            $this->assertNoProtectedResidentialAddressHistoryStepsOnRollback();
        }

        DB::transaction(function () use ($now, $insertedTemplateIds): void {
            if ($insertedTemplateIds !== []) {
                DB::table('onboarding_form_templates')
                    ->whereIn('id', $insertedTemplateIds)
                    ->delete();
            }

            DB::table('onboarding_form_templates')
                ->whereNull('tenant_id')
                ->where('sort_order', '>', 2)
                ->where(function (Builder $query): void {
                    $query->whereNull('template_key')
                        ->orWhere('template_key', '!=', 'residential_address_history');
                })
                ->decrement('sort_order');

            $this->removeResidentialAddressHistoryStepFromEmployees($now);
        });
    }

    private function assertNoProtectedResidentialAddressHistoryStepsOnRollback(): void
    {
        Employee::query()
            ->whereNotNull('onboarding_steps')
            ->select(['id', 'onboarding_steps'])
            ->orderBy('id')
            ->chunkById(500, function ($employees): void {
                foreach ($employees as $employee) {
                    if ($this->hasProtectedResidentialAddressHistoryStep($employee->onboarding_steps)) {
                        throw new RuntimeException(
                            'Cannot rollback residential address history migration while employees have completed residential address history onboarding steps.'
                        );
                    }
                }
            });
    }

    private function addResidentialAddressHistoryStepToEmployees(Carbon $now): void
    {
        Employee::query()
            ->whereNotNull('onboarding_steps')
            ->select(['id', 'status', 'onboarding_completed', 'onboarding_completed_at', 'onboarding_steps'])
            ->orderBy('id')
            ->chunkById(500, function ($employees) use ($now): void {
                foreach ($employees as $employee) {
                    $updatedOnboardingSteps = $this->insertResidentialAddressHistoryStep(
                        $employee->onboarding_steps
                    );
                    $shouldReopenOnboarding = $this->shouldReopenEmployeeOnboarding(
                        $employee,
                        $updatedOnboardingSteps
                    );

                    if ($updatedOnboardingSteps === $employee->onboarding_steps && ! $shouldReopenOnboarding) {
                        continue;
                    }

                    $updates = [
                        'updated_at' => $now,
                    ];

                    if ($updatedOnboardingSteps !== $employee->onboarding_steps) {
                        $updates['onboarding_steps'] = json_encode($updatedOnboardingSteps, JSON_THROW_ON_ERROR);
                    }

                    if ($shouldReopenOnboarding) {
                        $updates['onboarding_completed'] = false;
                    }

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update($updates);
                }
            });
    }

    private function removeResidentialAddressHistoryStepFromEmployees(Carbon $now): void
    {
        Employee::query()
            ->whereNotNull('onboarding_steps')
            ->select(['id', 'status', 'onboarding_completed', 'onboarding_completed_at', 'onboarding_workflow_status', 'onboarding_steps'])
            ->orderBy('id')
            ->chunkById(500, function ($employees) use ($now): void {
                foreach ($employees as $employee) {
                    $updatedOnboardingSteps = $this->removeResidentialAddressHistoryStep(
                        $employee->onboarding_steps
                    );
                    $shouldRestoreCompletedState = $this->shouldRestoreCompletedStateOnRollback(
                        $employee,
                        $updatedOnboardingSteps
                    );

                    if ($updatedOnboardingSteps === $employee->onboarding_steps && ! $shouldRestoreCompletedState) {
                        continue;
                    }

                    $updates = [
                        'updated_at' => $now,
                    ];

                    if ($updatedOnboardingSteps !== $employee->onboarding_steps) {
                        $updates['onboarding_steps'] = json_encode($updatedOnboardingSteps, JSON_THROW_ON_ERROR);
                    }

                    if ($shouldRestoreCompletedState) {
                        $updates['onboarding_completed'] = true;
                        $updates['onboarding_completed_at'] = $employee->onboarding_completed_at ?? $now;
                    }

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update($updates);
                }
            });
    }

    /**
     * @param  array<string, mixed>|null  $onboardingSteps
     * @return array<string, mixed>|null
     */
    private function insertResidentialAddressHistoryStep(?array $onboardingSteps): ?array
    {
        if (! is_array($onboardingSteps)) {
            return $onboardingSteps;
        }

        $steps = $onboardingSteps['steps'] ?? null;
        if (! is_array($steps)) {
            return $onboardingSteps;
        }

        $newStep = [
            'id' => 'residential_address_history',
            'name' => 'Wohnanschriften',
            'completed' => false,
            'completed_at' => null,
            'form_submission_id' => null,
        ];

        $updatedSteps = [];
        $hasExistingResidentialStep = collect($steps)
            ->contains(fn (mixed $step): bool => is_array($step) && (($step['id'] ?? null) === 'residential_address_history'));
        $inserted = false;

        foreach ($steps as $step) {
            if (! is_array($step)) {
                $updatedSteps[] = $step;

                continue;
            }

            if (($step['id'] ?? null) === 'residential_address_history') {
                if (! $inserted) {
                    $updatedSteps[] = $this->mergeResidentialAddressHistoryStep($this->arrayOnlyStringKeys($step));
                    $inserted = true;
                }

                continue;
            }

            $updatedSteps[] = $step;

            if (($step['id'] ?? null) === 'personal_data' && ! $inserted && ! $hasExistingResidentialStep) {
                $updatedSteps[] = $newStep;
                $inserted = true;
            }
        }

        if (! $inserted) {
            array_unshift($updatedSteps, $newStep);
        }

        return array_merge($onboardingSteps, ['steps' => $updatedSteps]);
    }

    /**
     * @param  array<string, mixed>  $existingStep
     * @return array<string, mixed>
     */
    private function mergeResidentialAddressHistoryStep(array $existingStep): array
    {
        return [
            'id' => 'residential_address_history',
            'name' => 'Wohnanschriften',
            'completed' => ($existingStep['completed'] ?? false) === true,
            'completed_at' => $existingStep['completed_at'] ?? null,
            'form_submission_id' => $existingStep['form_submission_id'] ?? null,
        ];
    }

    private function shouldReopenEmployeeOnboarding(
        Employee $employee,
        mixed $updatedOnboardingSteps,
    ): bool {
        if ($employee->status !== Employee::STATUS_PRE_CONTRACT || ! $employee->onboarding_completed) {
            return false;
        }

        return $this->hasIncompleteResidentialAddressHistoryStep($updatedOnboardingSteps);
    }

    private function hasIncompleteResidentialAddressHistoryStep(mixed $onboardingSteps): bool
    {
        $payload = $this->normalizeOnboardingStepsPayload($onboardingSteps);
        if ($payload === null) {
            return false;
        }

        $steps = $payload['steps'] ?? null;
        if (! is_array($steps)) {
            return false;
        }

        foreach ($steps as $step) {
            if (! is_array($step) || ($step['id'] ?? null) !== 'residential_address_history') {
                continue;
            }

            return ($step['completed'] ?? false) !== true;
        }

        return false;
    }

    private function shouldRestoreCompletedStateOnRollback(
        Employee $employee,
        mixed $updatedOnboardingSteps,
    ): bool {
        if (
            $employee->status !== Employee::STATUS_PRE_CONTRACT
            || $employee->onboarding_completed
            || $employee->onboarding_completed_at === null
            || ! $this->hasIncompleteResidentialAddressHistoryStep($employee->onboarding_steps)
        ) {
            return false;
        }

        return $this->allOnboardingStepsCompleted($updatedOnboardingSteps);
    }

    private function allOnboardingStepsCompleted(mixed $onboardingSteps): bool
    {
        $payload = $this->normalizeOnboardingStepsPayload($onboardingSteps);
        if ($payload === null) {
            return false;
        }

        $steps = $payload['steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            return false;
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            if (($step['completed'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $onboardingSteps
     * @return array<string, mixed>|null
     */
    private function removeResidentialAddressHistoryStep(?array $onboardingSteps): ?array
    {
        if (! is_array($onboardingSteps)) {
            return $onboardingSteps;
        }

        $steps = $onboardingSteps['steps'] ?? null;
        if (! is_array($steps)) {
            return $onboardingSteps;
        }

        return array_merge($onboardingSteps, [
            'steps' => array_values(array_filter(
                $steps,
                fn (mixed $step): bool => ! $this->shouldRemoveResidentialAddressHistoryStep($step)
            )),
        ]);
    }

    private function shouldRemoveResidentialAddressHistoryStep(mixed $step): bool
    {
        if (! is_array($step) || ($step['id'] ?? null) !== 'residential_address_history') {
            return false;
        }

        if ($this->isProtectedResidentialAddressHistoryStep($this->arrayOnlyStringKeys($step))) {
            return false;
        }

        return ($step['name'] ?? null) === 'Wohnanschriften'
            && ($step['completed'] ?? false) !== true
            && ($step['completed_at'] ?? null) === null
            && ($step['form_submission_id'] ?? null) === null;
    }

    /**
     * @param  array<string, mixed>|null  $onboardingSteps
     */
    private function hasProtectedResidentialAddressHistoryStep(?array $onboardingSteps): bool
    {
        $payload = $this->normalizeOnboardingStepsPayload($onboardingSteps);
        if ($payload === null) {
            return false;
        }

        $steps = $payload['steps'] ?? null;
        if (! is_array($steps)) {
            return false;
        }

        foreach ($steps as $step) {
            if (! is_array($step) || ($step['id'] ?? null) !== 'residential_address_history') {
                continue;
            }

            if ($this->isProtectedResidentialAddressHistoryStep($this->arrayOnlyStringKeys($step))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function isProtectedResidentialAddressHistoryStep(array $step): bool
    {
        if (($step['completed'] ?? false) === true) {
            return true;
        }

        if (($step['completed_at'] ?? null) !== null) {
            return true;
        }

        if (($step['form_submission_id'] ?? null) !== null) {
            return true;
        }

        $status = $step['status'] ?? null;

        return is_string($status) && in_array($status, ['completed', 'approved'], true);
    }

    /**
     * @param  array<mixed, mixed>  $row
     * @return array<string, mixed>
     */
    private function arrayOnlyStringKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeOnboardingStepsPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        return $this->arrayOnlyStringKeys($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function residentialAddressHistorySchema(): array
    {
        return ResidentialAddressHistorySchema::definition();
    }
};
