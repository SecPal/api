<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitOnboardingFormRequest;
use App\Http\Resources\OnboardingFormSubmissionResource;
use App\Http\Resources\OnboardingFormTemplateResource;
use App\Mail\OnboardingNameChangedMail;
use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Services\OnboardingCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

/**
 * OnboardingController handles pre-contract employee onboarding workflows.
 *
 * Pre-contract employees can:
 * - Complete magic link onboarding
 * - View onboarding steps
 * - View/submit onboarding forms
 * - Upload required documents
 *
 * All operations are protected by policies and EnsurePreContract middleware.
 */
class OnboardingController extends Controller
{
    /**
     * Validate onboarding token and return employee data for prefilling.
     *
     * GET /api/v1/onboarding/validate-token?token=xxx&email=xxx
     *
     * This is a public endpoint (no authentication required).
     * Used by frontend to validate token and prefill form with existing employee data.
     *
     * Security: Both token AND email must match to prevent token hijacking.
     */
    public function validateToken(Request $request): JsonResponse
    {
        /** @var array{token: string, email: string} $validated */
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        // Find token
        $tokenModel = EmployeeOnboardingToken::findByPlainToken($validated['token']);

        if (! $tokenModel || ! $tokenModel->isValid()) {
            return response()->json([
                'message' => __('Invalid or expired onboarding link. Please request a new invitation.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var Employee $employee */
        $employee = $tokenModel->employee;

        // SECURITY: Validate that email matches employee email
        if ($employee->email !== $validated['email']) {
            return response()->json([
                'message' => __('Invalid onboarding link. Email does not match.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return response()->json([
                'message' => __('Onboarding is only available for pre-contract employees.'),
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'data' => [
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
            ],
        ]);
    }

    /**
     * Complete onboarding with magic link token.
     *
     * POST /api/v1/onboarding/complete
     *
     * This is a public endpoint (no authentication required).
     * The token provides authentication.
     *
     * Security:
     * - Token is single-use (marked as completed)
     * - Token expires after 7 days
     * - Constant-time comparison prevents timing attacks
     * - Audit trail: IP and user agent stored
     */
    public function complete(Request $request): JsonResponse
    {
        /** @var array{token: string, email: string, password: string, first_name: string, last_name: string} $validated */
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', Password::defaults()],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ]);

        // Find token
        $tokenModel = EmployeeOnboardingToken::findByPlainToken($validated['token']);

        if (! $tokenModel || ! $tokenModel->isValid()) {
            return response()->json([
                'message' => __('Invalid or expired onboarding link. Please request a new invitation.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var Employee $employee */
        $employee = $tokenModel->employee;

        // SECURITY: Validate that email matches employee email
        if ($employee->email !== $validated['email']) {
            return response()->json([
                'message' => __('Invalid onboarding link. Email does not match.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return response()->json([
                'message' => __('Onboarding is only available for pre-contract employees.'),
            ], Response::HTTP_FORBIDDEN);
        }

        // Store old names for audit logging (before transaction)
        $oldFirstName = $employee->first_name;
        $oldLastName = $employee->last_name;

        // Extract validated data (PHPStan type safety)
        /** @var string $firstName */
        $firstName = $validated['first_name'];
        /** @var string $lastName */
        $lastName = $validated['last_name'];
        /** @var string $password */
        $password = $validated['password'];

        // Validate name changes (Hybrid approach: similarity check + HR notification)
        $firstNameValidation = null;
        $lastNameValidation = null;
        $shouldNotifyHR = false;
        $validationErrors = [];

        // Validate both names first, collect all errors before returning
        // This provides comprehensive feedback instead of one-at-a-time discovery
        if ($oldFirstName !== $firstName) {
            $firstNameValidation = $this->validateNameChange($oldFirstName, $firstName, 'first_name');
            if (! $firstNameValidation['allowed']) {
                $validationErrors['first_name'] = [$firstNameValidation['message']];
            } elseif ($firstNameValidation['severity'] !== 'minor') {
                $shouldNotifyHR = true;
            }
        }

        if ($oldLastName !== $lastName) {
            $lastNameValidation = $this->validateNameChange($oldLastName, $lastName, 'last_name');
            if (! $lastNameValidation['allowed']) {
                $validationErrors['last_name'] = [$lastNameValidation['message']];
            } elseif ($lastNameValidation['severity'] !== 'minor') {
                $shouldNotifyHR = true;
            }
        }

        // Return all validation errors at once for better UX
        if (! empty($validationErrors)) {
            return response()->json([
                'message' => __('Name change validation failed.'),
                'errors' => $validationErrors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Wrap all operations in a transaction to ensure atomicity
        DB::transaction(function () use ($employee, $firstName, $lastName, $password, $oldFirstName, $oldLastName, $firstNameValidation, $lastNameValidation, $shouldNotifyHR, $tokenModel, $request) {

            // Update employee name (allow corrections/updates)
            $employee->first_name = $firstName;
            $employee->last_name = $lastName;
            $employee->onboarding_started_at ??= now();
            $employee->save();

            // Enhanced activity logging if names changed
            if ($oldFirstName !== $firstName || $oldLastName !== $lastName) {
                // Reload user to ensure fresh data for activity log
                $employee->load('user');
                activity('employee-onboarding')
                    ->causedBy($employee->user)
                    ->performedOn($employee)
                    ->withProperties([
                        'action' => 'name_changed_during_onboarding',
                        'old_first_name' => $oldFirstName,
                        'new_first_name' => $firstName,
                        'old_last_name' => $oldLastName,
                        'new_last_name' => $lastName,
                        'first_name_similarity' => $firstNameValidation ? $firstNameValidation['similarity'] : 100,
                        'last_name_similarity' => $lastNameValidation ? $lastNameValidation['similarity'] : 100,
                        'first_name_severity' => $firstNameValidation ? $firstNameValidation['severity'] : 'none',
                        'last_name_severity' => $lastNameValidation ? $lastNameValidation['severity'] : 'none',
                        'ip' => $request->ip() ?? 'unknown',
                        'user_agent' => $request->userAgent() ?? 'unknown',
                    ])
                    ->log('Employee name changed during onboarding completion');

                // Send HR notification if name change is significant
                if ($shouldNotifyHR) {
                    Mail::to(config('mail.hr_email', config('mail.from.address')))
                        ->queue(new OnboardingNameChangedMail(
                            $employee,
                            $oldFirstName,
                            $oldLastName,
                            $firstNameValidation,
                            $lastNameValidation
                        ));
                }
            }

            // Set password on user and sync name
            $user = $employee->user;
            if (! $user) {
                throw new \RuntimeException(__('User account not found for employee.'));
            }

            $user->password = Hash::make($password);
            // Sync user name with employee name so it displays correctly after login
            $user->name = $firstName.' '.$lastName;
            $user->save();

            // Mark token as completed (only after all operations succeed)
            $ip = $request->ip() ?? 'unknown';
            $userAgent = $request->userAgent() ?? 'unknown';
            $tokenModel->markAsCompleted($ip, $userAgent);
        });

        // Create session token after transaction completes successfully
        $user = $employee->user;
        if (! $user) {
            return response()->json([
                'message' => __('User account not found for employee.'),
            ], Response::HTTP_NOT_FOUND);
        }

        // Refresh user to get updated name
        $user->refresh();

        // Automatically log the user in (create session with cookie, like regular login)
        // This uses session-based auth (Sanctum SPA mode) instead of token-based auth
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        // Log the automatic login after onboarding completion
        activity('authentication')
            ->causedBy($user)
            ->withProperties([
                'method' => 'onboarding_completion',
                'ip' => $request->ip() ?? 'unknown',
                'user_agent' => $request->userAgent() ?? 'unknown',
            ])
            ->log('User logged in after onboarding completion');

        $appName = config('app.name');

        return response()->json([
            'message' => __('Onboarding completed successfully. Welcome to :app_name!', ['app_name' => is_string($appName) ? $appName : 'SecPal']),
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'employee' => [
                    'id' => $employee->id,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'status' => $employee->status,
                ],
            ],
        ]);
    }

    /**
     * Get onboarding steps for authenticated pre-contract employee.
     *
     * GET /api/v1/onboarding/steps
     *
     * Returns onboarding_steps JSON from employee record.
     */
    public function getSteps(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var Employee|null $employee */
        $employee = $user->employee;

        $this->authorize('viewAny', OnboardingFormSubmission::class);

        if (! $employee) {
            return response()->json([
                'message' => __('No employee record found for user'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return response()->json([
                'message' => __('Onboarding is only available for pre-contract employees'),
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'data' => [
                'employee_id' => $employee->id,
                'status' => $employee->status,
                'onboarding_steps' => $employee->onboarding_steps,
                'onboarding_started_at' => $employee->onboarding_started_at?->toIso8601String(),
                'onboarding_completed' => $employee->onboarding_completed,
                'onboarding_completed_at' => $employee->onboarding_completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get available onboarding form templates.
     *
     * GET /api/v1/onboarding/templates
     *
     * Returns system templates + tenant-specific custom templates.
     */
    public function getTemplates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OnboardingFormTemplate::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        $templates = OnboardingFormTemplate::where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')
                ->orWhere('tenant_id', $tenantId);
        })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => OnboardingFormTemplateResource::collection($templates),
        ]);
    }

    /**
     * Get a specific form template.
     *
     * GET /api/v1/onboarding/templates/{template}
     */
    public function getTemplate(OnboardingFormTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        return response()->json([
            'data' => new OnboardingFormTemplateResource($template),
        ]);
    }

    /**
     * Get authenticated employee's form submissions.
     *
     * GET /api/v1/onboarding/submissions
     */
    public function getSubmissions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OnboardingFormSubmission::class);

        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var Employee|null $employee */
        $employee = $user->employee;

        if (! $employee) {
            return response()->json([
                'message' => __('No employee record found for user'),
            ], Response::HTTP_NOT_FOUND);
        }

        $submissions = $employee->onboardingSubmissions()
            ->with('formTemplate')
            ->get();

        return response()->json([
            'data' => OnboardingFormSubmissionResource::collection($submissions),
        ]);
    }

    /**
     * Submit an onboarding form.
     *
     * POST /api/v1/onboarding/submissions
     *
     * Creates or updates form submission (draft or submitted status).
     */
    public function submitForm(SubmitOnboardingFormRequest $request): JsonResponse
    {
        $this->authorize('create', OnboardingFormSubmission::class);

        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var Employee|null $employee */
        $employee = $user->employee;

        if (! $employee) {
            return response()->json([
                'message' => __('No employee record found for user'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return response()->json([
                'message' => __('Onboarding forms can only be submitted by pre-contract employees'),
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // Check if submission already exists
        $existing = OnboardingFormSubmission::where('employee_id', $employee->id)
            ->where('form_template_id', $validated['form_template_id'])
            ->first();

        if ($existing && $existing->status === 'submitted') {
            return response()->json([
                'message' => __('Form has already been submitted and is awaiting review'),
            ], Response::HTTP_CONFLICT);
        }

        if ($existing && in_array($existing->status, ['approved', 'rejected'], true)) {
            return response()->json([
                'message' => __('Form has already been reviewed and cannot be modified'),
            ], Response::HTTP_CONFLICT);
        }

        $status = $validated['status'] ?? 'submitted';
        $submittedAt = $status === 'submitted' ? now() : null;

        if ($existing) {
            // Update existing draft
            $existing->update([
                'form_data' => $validated['form_data'],
                'status' => $status,
                'submitted_at' => $submittedAt,
            ]);
            $existing->refresh();
            $submission = $existing;
        } else {
            // Create new submission
            $submission = OnboardingFormSubmission::create([
                'employee_id' => $employee->id,
                'form_template_id' => $validated['form_template_id'],
                'form_data' => $validated['form_data'],
                'status' => $status,
                'submitted_at' => $submittedAt,
            ]);
        }

        $submission->load('formTemplate');

        // Check if onboarding is now complete (only for submitted, not draft)
        if ($status === 'submitted') {
            app(OnboardingCompletionService::class)->checkCompletion($employee);
        }

        return response()->json([
            'data' => new OnboardingFormSubmissionResource($submission),
        ], $existing ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * Approve onboarding form submission (HR only).
     *
     * POST /api/v1/admin/onboarding/submissions/{submission}/approve
     */
    public function approveSubmission(Request $request, OnboardingFormSubmission $submission): JsonResponse
    {
        $this->authorize('approve', $submission);

        if ($submission->status !== 'submitted') {
            return response()->json([
                'message' => __('Only submitted forms can be approved'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        $submission->update([
            'status' => 'approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        /** @var OnboardingFormSubmission $fresh */
        $fresh = $submission->fresh();
        $fresh->load(['formTemplate', 'reviewer']);

        // Check if employee's onboarding is now complete after approval
        /** @var Employee $employee */
        $employee = $submission->employee;
        app(OnboardingCompletionService::class)->checkCompletion($employee);

        return response()->json([
            'data' => new OnboardingFormSubmissionResource($fresh),
        ]);
    }

    /**
     * Reject onboarding form submission (HR only).
     *
     * POST /api/v1/admin/onboarding/submissions/{submission}/reject
     */
    public function rejectSubmission(Request $request, OnboardingFormSubmission $submission): JsonResponse
    {
        $this->authorize('reject', $submission);

        if ($submission->status !== 'submitted') {
            return response()->json([
                'message' => __('Only submitted forms can be rejected'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $submission->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $request->input('reason'),
        ]);

        /** @var OnboardingFormSubmission $fresh */
        $fresh = $submission->fresh();
        $fresh->load(['formTemplate', 'reviewer']);

        return response()->json([
            'data' => new OnboardingFormSubmissionResource($fresh),
        ]);
    }

    /**
     * Calculate name similarity percentage (0-100).
     *
     * Uses Levenshtein distance to detect typo corrections vs. fundamental changes.
     * Special handling for common patterns:
     * - Hyphenated names (Hans → Hans-Peter)
     * - Double names (Müller → Müller-Schmidt)
     * - Umlaut variations (Mueller → Müller)
     *
     * Examples:
     * - "Hans" vs "Hanns" = ~90% (typo)
     * - "Müller" vs "Mueller" = ~85% (umlaut)
     * - "Hans" vs "Hans-Peter" = ~70% (addition)
     * - "Hans" vs "Maria" = ~20% (different name)
     *
     * @param  string  $original  Original name from HR
     * @param  string  $new  New name from employee
     * @return float Similarity percentage (0-100)
     */
    private function calculateNameSimilarity(string $original, string $new): float
    {
        // Normalize: lowercase, trim
        $original = mb_strtolower(trim($original));
        $new = mb_strtolower(trim($new));

        // Same name = 100%
        if ($original === $new) {
            return 100.0;
        }

        // Special case: Umlaut normalization (Müller ↔ Mueller, Schön ↔ Schoen)
        $originalNormalized = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $original);
        $newNormalized = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $new);

        if ($originalNormalized === $newNormalized || $originalNormalized === $new || $original === $newNormalized) {
            // Umlaut variation detected - treat as minor correction
            return 90.0;
        }

        // Special case: New name contains original as prefix (Hans → Hans-Peter OR Max → Maximilian)
        // This indicates addition of name components, not a fundamental change
        $isNewPrefixedByOriginal = str_starts_with($new, $original.'-') ||
                                   str_starts_with($new, $original.' ') ||
                                   (str_starts_with($new, $original) && mb_strlen($original) >= 3);

        if ($isNewPrefixedByOriginal) {
            // Calculate based on ratio of added characters
            $addedChars = mb_strlen($new) - mb_strlen($original);
            $penalty = ($addedChars / mb_strlen($new)) * 40; // Max 40% penalty for additions

            return max(50.0, 100.0 - $penalty);
        }

        // Same check reversed: Original contains new as prefix
        $isOriginalPrefixedByNew = str_starts_with($original, $new.'-') ||
                                   str_starts_with($original, $new.' ') ||
                                   (str_starts_with($original, $new) && mb_strlen($new) >= 3);

        if ($isOriginalPrefixedByNew) {
            // Employee removed part of name
            $removedChars = mb_strlen($original) - mb_strlen($new);
            $penalty = ($removedChars / mb_strlen($original)) * 40;

            return max(50.0, 100.0 - $penalty);
        }

        // Use Levenshtein distance for other cases
        $maxLen = max(mb_strlen($original), mb_strlen($new));
        if ($maxLen === 0) {
            return 100.0;
        }

        $distance = $this->mbLevenshtein($original, $new);
        $similarity = (1 - ($distance / $maxLen)) * 100;

        return max(0.0, min(100.0, $similarity));
    }

    /**
     * Multibyte-safe Levenshtein distance implementation for UTF-8 strings.
     *
     * PHP's built-in levenshtein() has two critical limitations:
     * 1. 255 character limit per string (returns -1 if exceeded)
     * 2. Not multibyte-safe (treats bytes not characters for UTF-8)
     *
     * This custom implementation:
     * - Supports unlimited string length
     * - Properly handles UTF-8 multibyte characters (German umlauts, etc.)
     * - Uses dynamic programming algorithm (O(m*n) time, O(n) space)
     *
     * @param  string  $str1  First string (UTF-8)
     * @param  string  $str2  Second string (UTF-8)
     * @return int Minimum number of single-character edits (insertions, deletions, substitutions)
     */
    private function mbLevenshtein(string $str1, string $str2): int
    {
        // Split into UTF-8 character arrays
        $chars1 = preg_split('//u', $str1, -1, PREG_SPLIT_NO_EMPTY);
        $chars2 = preg_split('//u', $str2, -1, PREG_SPLIT_NO_EMPTY);

        // Ensure we got arrays back (preg_split can return false on error)
        if (! is_array($chars1) || ! is_array($chars2)) {
            return max(mb_strlen($str1), mb_strlen($str2)); // fallback to max length
        }

        $len1 = count($chars1);
        $len2 = count($chars2);

        // Base cases
        if ($len1 === 0) {
            return $len2;
        }
        if ($len2 === 0) {
            return $len1;
        }

        // Initialize first row (0, 1, 2, ..., len2)
        $previousRow = range(0, $len2);

        // Calculate Levenshtein distance using dynamic programming
        for ($i = 1; $i <= $len1; $i++) {
            $currentRow = [$i]; // First column is always $i

            for ($j = 1; $j <= $len2; $j++) {
                $cost = $chars1[$i - 1] === $chars2[$j - 1] ? 0 : 1;

                $currentRow[$j] = min(
                    $currentRow[$j - 1] + 1,      // insertion
                    $previousRow[$j] + 1,         // deletion
                    $previousRow[$j - 1] + $cost  // substitution
                );
            }

            $previousRow = $currentRow;
        }

        return $previousRow[$len2];
    }

    /**
     * Validate name change and return validation result.
     *
     * Three tiers:
     * - >80% similarity: Minor correction (typo) - ALLOWED
     * - 50-80% similarity: Medium change (additional name) - WARN but ALLOW
     * - <50% similarity: Major change (different name) - BLOCK
     *
     * @param  string  $oldName  Original name
     * @param  string  $newName  New name
     * @param  string  $fieldName  Field name for error messages ('first_name' or 'last_name')
     * @return array{allowed: bool, severity: string, similarity: float, message: string|null}
     */
    private function validateNameChange(string $oldName, string $newName, string $fieldName): array
    {
        $similarity = $this->calculateNameSimilarity($oldName, $newName);
        $fieldLabel = $fieldName === 'first_name' ? __('First name') : __('Last name');

        if ($similarity >= 80) {
            return [
                'allowed' => true,
                'severity' => 'minor',
                'similarity' => $similarity,
                'message' => null,
            ];
        }

        if ($similarity >= 50) {
            return [
                'allowed' => true,
                'severity' => 'medium',
                'similarity' => $similarity,
                'message' => __(':field change detected. HR will be notified for verification.', ['field' => $fieldLabel]),
            ];
        }

        // <50% similarity: Block
        return [
            'allowed' => false,
            'severity' => 'major',
            'similarity' => $similarity,
            'message' => __(':field change too significant. Please contact HR if your name was entered incorrectly.', ['field' => $fieldLabel]),
        ];
    }

    /**
     * Get onboarding completion status for authenticated employee.
     *
     * GET /api/v1/onboarding/completion-status
     *
     * Returns:
     * - is_completed: bool - Overall completion status
     * - total_required: int - Total number of required templates
     * - completed_required: int - Number of completed required templates
     * - missing_templates: array - List of templates not yet completed (id, name, description)
     *
     * Protected by auth:sanctum middleware (employee must be authenticated).
     */
    public function getCompletionStatus(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var Employee|null $employee */
        $employee = $user->employee;

        if (! $employee) {
            return response()->json([
                'message' => __('No employee record found for user'),
            ], Response::HTTP_NOT_FOUND);
        }

        $status = app(OnboardingCompletionService::class)->getCompletionStatus($employee);

        return response()->json([
            'data' => $status,
        ]);
    }
}
