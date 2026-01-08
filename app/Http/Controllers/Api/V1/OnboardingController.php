<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitOnboardingFormRequest;
use App\Http\Resources\OnboardingFormSubmissionResource;
use App\Http\Resources\OnboardingFormTemplateResource;
use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
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
        /** @var array{token: string, password: string, first_name: string, last_name: string, photo?: mixed} $validated */
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', Password::defaults()],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:2048'], // 2MB max
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

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return response()->json([
                'message' => __('Onboarding is only available for pre-contract employees.'),
            ], Response::HTTP_FORBIDDEN);
        }

        // Update employee name if provided
        $employee->first_name = $validated['first_name'];
        $employee->last_name = $validated['last_name'];
        $employee->onboarding_started_at ??= now();
        $employee->save();

        // Set password on user
        $user = $employee->user;
        if (! $user) {
            return response()->json([
                'message' => __('User account not found for employee.'),
            ], Response::HTTP_NOT_FOUND);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Mark token as completed
        $ip = $request->ip() ?? 'unknown';
        $userAgent = $request->userAgent() ?? 'unknown';
        $tokenModel->markAsCompleted($ip, $userAgent);

        // Create session token
        $token = $user->createToken('onboarding-completion')->plainTextToken;

        $appName = config('app.name');
        return response()->json([
            'message' => __('Onboarding completed successfully. Welcome to :app_name!', ['app_name' => is_string($appName) ? $appName : 'SecPal']),
            'data' => [
                'token' => $token,
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
}
