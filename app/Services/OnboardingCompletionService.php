<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;

/**
 * OnboardingCompletionService handles automatic detection and tracking of onboarding completion.
 *
 * Completion criteria:
 * - All templates marked as is_required=true must have approved submissions
 * - Only 'approved' status submissions count towards completion
 * - Optional templates (is_required=false) do not affect completion
 *
 * The service automatically updates Employee.onboarding_completed and Employee.onboarding_completed_at
 * when all required templates are submitted and approved.
 */
class OnboardingCompletionService
{
    /**
     * Check if all required onboarding templates are completed for the given employee.
     *
     * This method automatically updates the employee's onboarding_completed flag
     * and onboarding_completed_at timestamp if completion is detected.
     *
     * Activity logging is triggered when completion status changes to true.
     *
     * @param  Employee  $employee  The employee to check completion for
     * @return bool True if onboarding is complete, false otherwise
     */
    public function checkCompletion(Employee $employee): bool
    {
        // Get all required template IDs (system templates only, tenant-agnostic)
        $requiredTemplateIds = OnboardingFormTemplate::where('is_required', true)
            ->whereNull('tenant_id') // System templates only
            ->pluck('id')
            ->toArray();

        // No required templates = instant completion
        if (count($requiredTemplateIds) === 0) {
            return $this->markAsCompleted($employee, wasAlreadyComplete: $employee->onboarding_completed ?? false);
        }

        // Get all approved submissions for this employee
        $approvedSubmissionTemplateIds = OnboardingFormSubmission::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereIn('form_template_id', $requiredTemplateIds)
            ->pluck('form_template_id')
            ->unique()
            ->toArray();

        // Check if all required templates have been submitted and approved
        $allRequiredCompleted = count(array_diff($requiredTemplateIds, $approvedSubmissionTemplateIds)) === 0;

        if ($allRequiredCompleted) {
            return $this->markAsCompleted($employee, wasAlreadyComplete: $employee->onboarding_completed ?? false);
        }

        // Onboarding not yet complete
        return false;
    }

    /**
     * Get detailed onboarding completion status for the given employee.
     *
     * Returns comprehensive status including:
     * - Overall completion flag
     * - Total required templates count
     * - Completed required templates count
     * - List of missing required templates (with names)
     *
     * This method does NOT modify the employee record. Use checkCompletion() to auto-update.
     *
     * @param  Employee  $employee  The employee to get status for
     * @return array{is_completed: bool, total_required: int, completed_required: int, missing_templates: array<int, array{id: string, name: string, description: string|null}>}
     */
    public function getCompletionStatus(Employee $employee): array
    {
        // Get all required templates (system templates only)
        $requiredTemplates = OnboardingFormTemplate::where('is_required', true)
            ->whereNull('tenant_id')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'description']);

        $totalRequired = $requiredTemplates->count();

        // No required templates = instant completion
        if ($totalRequired === 0) {
            return [
                'is_completed' => true,
                'total_required' => 0,
                'completed_required' => 0,
                'missing_templates' => [],
            ];
        }

        // Get approved submission template IDs for this employee
        $approvedTemplateIds = OnboardingFormSubmission::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereIn('form_template_id', $requiredTemplates->pluck('id'))
            ->pluck('form_template_id')
            ->unique()
            ->toArray();

        $completedRequired = count($approvedTemplateIds);

        // Identify missing templates
        $missingTemplates = $requiredTemplates
            ->whereNotIn('id', $approvedTemplateIds)
            ->map(fn ($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
            ])
            ->values()
            ->toArray();

        $isCompleted = count($missingTemplates) === 0;

        return [
            'is_completed' => $isCompleted,
            'total_required' => $totalRequired,
            'completed_required' => $completedRequired,
            'missing_templates' => $missingTemplates,
        ];
    }

    /**
     * Mark employee as onboarding completed and log activity.
     *
     * Only logs activity if onboarding was not already complete.
     *
     * @param  Employee  $employee  The employee to mark as complete
     * @param  bool  $wasAlreadyComplete  Was the employee already marked complete before this call?
     * @return bool Always returns true
     */
    private function markAsCompleted(Employee $employee, bool $wasAlreadyComplete): bool
    {
        // Skip update if already completed
        if ($wasAlreadyComplete) {
            return true;
        }

        // Update employee record
        $employee->update([
            'onboarding_completed' => true,
            'onboarding_completed_at' => now(),
        ]);

        // Log activity (only if newly completed)
        activity()
            ->performedOn($employee)
            ->causedBy($employee) // Employee themselves completed onboarding
            ->event('onboarding_completed')
            ->log('Employee completed all required onboarding forms');

        return true;
    }
}
