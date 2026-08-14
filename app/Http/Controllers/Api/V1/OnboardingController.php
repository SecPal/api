<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitOnboardingFormRequest;
use App\Http\Requests\UpdateOnboardingSubmissionRequest;
use App\Http\Requests\UploadOnboardingSubmissionFileRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\OnboardingFormSubmissionResource;
use App\Http\Resources\OnboardingFormTemplateResource;
use App\Mail\OnboardingNameChangedMail;
use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\OnboardingSubmissionFile;
use App\Services\OnboardingCompletionService;
use App\Services\OnboardingFormDataSchemaValidationService;
use App\Services\OnboardingResidentialAddressHistorySyncService;
use App\Services\OnboardingSchemaLocalizationService;
use App\Services\OnboardingSubmissionFileUploadService;
use App\Services\OnboardingTaxIdentificationSyncService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

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
    private const ID_DOCUMENT_UPLOAD_NOW_FIELD = 'id_document_upload_now';

    private const ID_DOCUMENT_KIND_FIELD = 'id_document_kind';

    private const RESIDENCE_PERMIT_UPLOAD_NOW_FIELD = 'residence_permit_upload_now';

    private const RESIDENCE_PERMIT_TITLE_FIELD = 'residence_permit_title';

    private const RESIDENCE_PERMIT_UNLIMITED_FIELD = 'residence_permit_unlimited';

    private const RESIDENCE_PERMIT_EXPIRY_FIELD = 'residence_permit_expiry';

    private const RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD = 'residence_permit_employment_allowed';

    private const CONTRACT_START_DATE_FIELD = 'contract_start_date';

    /**
     * Must stay in sync with frontend gating.
     *
     * @var list<string>
     */
    private const RESIDENCE_TITLE_EXEMPT_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MT', 'NL',
        'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public function __construct(
        private readonly OnboardingSubmissionFileUploadService $submissionFileUploadService,
        private readonly OnboardingSchemaLocalizationService $onboardingSchemaLocalizationService,
        private readonly OnboardingFormDataSchemaValidationService $onboardingFormDataSchemaValidationService,
        private readonly OnboardingCompletionService $onboardingCompletionService,
        private readonly OnboardingResidentialAddressHistorySyncService $onboardingResidentialAddressHistorySyncService,
        private readonly OnboardingTaxIdentificationSyncService $onboardingTaxIdentificationSyncService,
    ) {}

    /**
     * Validate onboarding token (existence + email match) without leaking personal data.
     *
     * GET /api/v1/onboarding/validate-token?token=xxx&email=xxx
     *
     * This is a public endpoint (no authentication required). It only confirms whether
     * the link can proceed to the completion form. To prevent anyone who intercepts the
     * invitation link from harvesting employee details, NO personal data (first name,
     * last name, …) is returned here. The actual identity proof (date of birth, name)
     * is verified inside POST /onboarding/complete.
     *
     * Security: Both token AND email must match. We intentionally return the same
     * generic 422 for "token unknown", "token expired", and "email does not match"
     * so this endpoint cannot be used as a token-validity oracle.
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
                'message' => __('Invalid or expired onboarding link. Please request a new invitation.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return response()->json([
                'message' => __('Onboarding is only available for pre-contract employees.'),
            ], Response::HTTP_FORBIDDEN);
        }

        // Intentionally do NOT echo first_name / last_name here. The form must collect
        // them from the invitee directly and POST /onboarding/complete verifies them
        // together with the date of birth before any account state is mutated.
        return response()->json([
            'data' => [
                'valid' => true,
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
        /** @var array{token: string, email: string, password: string, first_name: string, last_name: string, date_of_birth: string} $validated */
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', Password::defaults()],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'string', 'date_format:Y-m-d', 'before:today'],
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
                'message' => __('Invalid or expired onboarding link. Please request a new invitation.'),
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
        /** @var string $onboardingEmail */
        $onboardingEmail = $validated['email'];
        /** @var string $submittedDateOfBirth */
        $submittedDateOfBirth = $validated['date_of_birth'];

        // SECURITY: Identity proof. Anyone with the link knows token + email; before we
        // touch the employee record (or even hint at any stored data) the invitee must
        // additionally prove knowledge of the date of birth on file AND a name that is
        // similar enough to the HR-maintained record.
        //
        // We deliberately return ONE generic message for all identity mismatches (DOB
        // wrong, name too different, both) and no `errors` payload, so an attacker
        // cannot use the response as an oracle to enumerate one field at a time. The
        // throttle bucket in AppServiceProvider::shouldCountOnboardingAttempt counts
        // 422 responses without an `errors` key, so each failed attempt is rate-limited.
        $storedDateOfBirth = $this->normalizeDateOfBirthForIdentityCheck($employee->date_of_birth);
        $normalizedSubmittedDateOfBirth = $this->normalizeDateOfBirthForIdentityCheck($submittedDateOfBirth);

        if ($storedDateOfBirth === null) {
            // Missing or legacy-unparseable HR data is not evidence of an attack.
            // Keep the token usable so HR can correct the employee record first.
            return response()->json([
                'message' => __('Onboarding cannot be completed because your HR record is incomplete. Please contact HR before retrying this onboarding link.'),
            ], Response::HTTP_CONFLICT);
        }

        // $storedDateOfBirth is non-null (checked above). $normalizedSubmittedDateOfBirth
        // is expected to be non-null because the date_format:Y-m-d rule already rejected
        // any unparseable submitted value. Guard defensively so that a future change to
        // the validation rules cannot accidentally burn a legitimate token.
        if ($normalizedSubmittedDateOfBirth === null) {
            return response()->json([
                'message' => __('Onboarding cannot be completed because your HR record is incomplete. Please contact HR before retrying this onboarding link.'),
            ], Response::HTTP_CONFLICT);
        }

        $dateOfBirthMatches = hash_equals($storedDateOfBirth, $normalizedSubmittedDateOfBirth);

        // Validate name changes (Hybrid approach: similarity check + HR notification).
        // We still gather severity for audit logging / HR notifications, but a "major"
        // mismatch no longer leaks via a field-scoped 422 — it counts as an identity
        // verification failure below.
        $firstNameValidation = $oldFirstName !== $firstName
            ? $this->validateNameChange($oldFirstName, $firstName, 'first_name')
            : null;
        $lastNameValidation = $oldLastName !== $lastName
            ? $this->validateNameChange($oldLastName, $lastName, 'last_name')
            : null;

        $namesAccepted = ($firstNameValidation === null || $firstNameValidation['allowed'])
            && ($lastNameValidation === null || $lastNameValidation['allowed']);

        if (! $dateOfBirthMatches || ! $namesAccepted) {
            // SECURITY: single-shot identity proof. A legitimate invitee knows
            // their own DOB and name; failing this check means either the link
            // was stolen or somebody is guessing. We do not give a second try
            // with the same link — HR must issue a new invitation.
            $ip = $request->ip() ?? 'unknown';
            $userAgent = $request->userAgent() ?? 'unknown';

            DB::transaction(function () use (
                $tokenModel,
                $employee,
                $ip,
                $userAgent,
                $dateOfBirthMatches,
                $firstNameValidation,
                $lastNameValidation,
            ): void {
                // Lock the token row so concurrent duplicate submissions cannot
                // both pass the isValid() check outside this transaction and
                // double-write the audit log. If the row is already burned or
                // completed we skip the write — the caller will still receive
                // the same 422 because findByPlainToken already filtered it out.
                $locked = EmployeeOnboardingToken::whereKey($tokenModel->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || ! $locked->isValid()) {
                    return;
                }

                $locked->markAsInvalidated($ip, $userAgent, 'identity_verification_failed');

                activity('employee-onboarding')
                    ->performedOn($employee)
                    ->withProperties([
                        'reason' => 'identity_verification_failed',
                        'date_of_birth_matched' => $dateOfBirthMatches,
                        'first_name_severity' => $firstNameValidation['severity'] ?? 'none',
                        'last_name_severity' => $lastNameValidation['severity'] ?? 'none',
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                    ])
                    ->log('Onboarding link invalidated due to failed identity verification');
            });

            return response()->json([
                'message' => __('We could not verify your identity with the details provided. For security reasons this onboarding link has been deactivated. Please contact HR for a new invitation.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $shouldNotifyHR = false;
        if ($firstNameValidation !== null && $firstNameValidation['severity'] !== 'minor') {
            $shouldNotifyHR = true;
        }
        if ($lastNameValidation !== null && $lastNameValidation['severity'] !== 'minor') {
            $shouldNotifyHR = true;
        }

        // Wrap all operations in a transaction to ensure atomicity
        DB::transaction(function () use ($employee, $firstName, $lastName, $password, $onboardingEmail, $oldFirstName, $oldLastName, $firstNameValidation, $lastNameValidation, $shouldNotifyHR, $tokenModel, $request) {

            // Update employee name (allow corrections/updates)
            $employee->first_name = $firstName;
            $employee->last_name = $lastName;
            $employee->onboarding_started_at ??= now();
            $employee->save();
            if ($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED)) {
                $employee->transitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED);
            }

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
            // The onboarding invite proves control over employee.email; keep the user login email aligned.
            if ($user->email !== $onboardingEmail) {
                $user->email = $onboardingEmail;
                $user->email_verified_at = null;
            }
            $user->save();
            // Consuming a valid onboarding magic link proves mailbox control.
            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }

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
                    'email_verified' => $user->hasVerifiedEmail(),
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
                'onboarding_started_at' => \App\Support\ApiTimestamp::nullable($employee->onboarding_started_at),
                'onboarding_completed' => $employee->onboarding_completed,
                'onboarding_completed_at' => \App\Support\ApiTimestamp::nullable($employee->onboarding_completed_at),
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

        $this->prepareLocalizedTemplates($templates, $this->resolveTemplateLocale($request));

        return response()->json([
            'data' => OnboardingFormTemplateResource::collection($templates),
        ]);
    }

    /**
     * Get a localized nationality list for onboarding forms.
     *
     * GET /api/v1/onboarding/nationalities
     *
     * Source: static ISO alpha-2 code list in config/onboarding_nationalities.php.
     * Display names are localized using the resolved onboarding locale.
     */
    public function getNationalities(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OnboardingFormTemplate::class);

        $locale = $this->resolveTemplateLocale($request);
        $configuredCodes = config('onboarding_nationalities.iso_alpha2_codes', []);

        if (! is_array($configuredCodes)) {
            $configuredCodes = [];
        }

        $options = [];

        foreach ($configuredCodes as $code) {
            if (! is_string($code) || ! preg_match('/^[A-Z]{2}$/', $code)) {
                continue;
            }

            $options[] = [
                'code' => $code,
                'name' => $this->resolveCountryDisplayName($code, $locale),
            ];
        }

        $collator = class_exists(\Collator::class) ? \Collator::create($locale) : null;

        usort($options, static function (array $left, array $right) use ($collator): int {
            $leftName = (string) $left['name'];
            $rightName = (string) $right['name'];

            if ($collator instanceof \Collator) {
                $comparison = $collator->compare($leftName, $rightName);

                if ($comparison !== false) {
                    return $comparison;
                }
            }

            return strcasecmp($leftName, $rightName);
        });

        return response()->json([
            'data' => $options,
        ]);
    }

    /**
     * Get a specific form template.
     *
     * GET /api/v1/onboarding/templates/{template}
     */
    public function getTemplate(Request $request, OnboardingFormTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        $this->prepareLocalizedTemplate($template, $this->resolveTemplateLocale($request));

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

        $this->prepareLocalizedSubmissionTemplates($submissions, $this->resolveTemplateLocale($request));

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

        $employee = $employee->normalizeAuthenticatedOnboardingWorkflow();

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

        if ($existing && $existing->status === 'approved') {
            return response()->json([
                'message' => __('Form has already been reviewed and cannot be modified'),
            ], Response::HTTP_CONFLICT);
        }

        $status = $validated['status'] ?? 'submitted';
        $submittedAt = $status === 'submitted' ? now() : null;

        $template = OnboardingFormTemplate::query()
            ->whereKey($validated['form_template_id'])
            ->where(function ($q) use ($employee): void {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $employee->tenant_id);
            })
            ->firstOrFail();

        /** @var array<string, mixed> $formData */
        $formData = (array) ($validated['form_data'] ?? []);
        $this->onboardingFormDataSchemaValidationService->assertMatchesTemplate(
            $template,
            $formData,
            $status === 'submitted',
            $employee,
        );
        if ($status === 'submitted') {
            $this->assertUploadDecisionRequirements(
                $formData,
                is_array($template->form_schema) ? $template->form_schema : [],
                $existing,
                $employee
            );
        }

        $submission = DB::transaction(function () use ($existing, $validated, $formData, $status, $submittedAt, $employee): OnboardingFormSubmission {
            if ($existing) {
                // Update existing draft
                $existing->update([
                    'form_data' => $formData,
                    'status' => $status,
                    'submitted_at' => $submittedAt,
                    'reviewed_by' => $existing->status === 'rejected' ? null : $existing->reviewed_by,
                    'reviewed_at' => $existing->status === 'rejected' ? null : $existing->reviewed_at,
                    'review_notes' => $existing->status === 'rejected' ? null : $existing->review_notes,
                ]);
                $existing->refresh();
                $created = $existing;
            } else {
                // Create new submission
                $created = OnboardingFormSubmission::create([
                    'employee_id' => $employee->id,
                    'form_template_id' => $validated['form_template_id'],
                    'form_data' => $formData,
                    'status' => $status,
                    'submitted_at' => $submittedAt,
                ]);
            }

            $targetWorkflowStatus = $status === 'submitted'
                ? Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW
                : Employee::WORKFLOW_STATUS_IN_PROGRESS;

            if (! $employee->canTransitionOnboardingWorkflowTo($targetWorkflowStatus)) {
                throw ValidationException::withMessages([
                    'onboarding_workflow_status' => __('Cannot submit: onboarding workflow is not in an expected state for this action'),
                ]);
            }

            $employee->transitionOnboardingWorkflowTo($targetWorkflowStatus);

            return $created;
        });

        $submission->load('formTemplate');

        // Check if onboarding is now complete (only for submitted, not draft)
        if ($status === 'submitted') {
            app(OnboardingCompletionService::class)->checkCompletion($employee);
        }

        $this->prepareLocalizedSubmissionTemplate($submission, $this->resolveTemplateLocale($request));

        return response()->json([
            'data' => new OnboardingFormSubmissionResource($submission),
        ], $existing ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * Update an editable onboarding submission.
     *
     * PATCH /v1/onboarding/submissions/{submission}
     */
    public function updateSubmission(UpdateOnboardingSubmissionRequest $request, OnboardingFormSubmission $submission): JsonResponse
    {
        $this->authorize('update', $submission);

        if (! in_array($submission->status, ['draft', 'rejected'], true)) {
            $message = match ($submission->status) {
                'submitted' => __('Form has already been submitted and is awaiting review'),
                'approved' => __('Form has already been reviewed and approved'),
                default => __('Form cannot be updated in its current state'),
            };

            return response()->json(['message' => $message], Response::HTTP_CONFLICT);
        }

        /** @var Employee|null $employee */
        $employee = $submission->employee;

        if (! $employee) {
            return response()->json([
                'message' => __('No employee record found for submission'),
            ], Response::HTTP_NOT_FOUND);
        }

        $employee = $employee->normalizeAuthenticatedOnboardingWorkflow();

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return response()->json([
                'message' => __('This action is only available for pre-contract employees'),
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $wasRejected = $submission->status === 'rejected';
        $status = array_key_exists('status', $validated)
            ? $validated['status']
            : ($wasRejected ? 'draft' : $submission->status);
        $submittedAt = $status === 'submitted' ? now() : null;
        $shouldResetReview = $wasRejected && $status !== 'rejected';

        $submission->loadMissing('formTemplate');
        $formTemplate = $submission->formTemplate;
        if ($formTemplate === null) {
            return response()->json([
                'message' => __('Form template not found for this submission'),
            ], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $storedFormData */
        $storedFormData = is_array($submission->form_data) ? $submission->form_data : [];

        // Guard: a list-type root payload cannot be safely merged into the stored
        // associative object; treat it as if no form_data was provided to prevent
        // numeric keys from corrupting the stored structure.
        /** @var non-empty-array<string, mixed>|null $incomingFormData */
        $incomingFormData = isset($validated['form_data']) && is_array($validated['form_data']) && ! array_is_list($validated['form_data'])
            ? $validated['form_data']
            : null;

        /** @var array<string, mixed> $effectiveFormData */
        $effectiveFormData = is_array($incomingFormData)
            ? $this->mergeSubmissionFormData($storedFormData, $incomingFormData)
            : $storedFormData;

        $this->onboardingFormDataSchemaValidationService->assertMatchesTemplate(
            $formTemplate,
            $effectiveFormData,
            $status === 'submitted',
            $employee,
        );
        if ($status === 'submitted') {
            $this->assertUploadDecisionRequirements(
                $effectiveFormData,
                is_array($formTemplate->form_schema) ? $formTemplate->form_schema : [],
                $submission,
                $employee
            );
        }

        $submission = DB::transaction(function () use ($employee, $status, $submittedAt, $submission, $effectiveFormData, $shouldResetReview): OnboardingFormSubmission {
            $submission->update([
                'form_data' => $effectiveFormData,
                'status' => $status,
                'submitted_at' => $submittedAt,
                'reviewed_by' => $shouldResetReview ? null : $submission->reviewed_by,
                'reviewed_at' => $shouldResetReview ? null : $submission->reviewed_at,
                'review_notes' => $shouldResetReview ? null : $submission->review_notes,
            ]);

            $targetWorkflowStatus = $status === 'submitted'
                ? Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW
                : Employee::WORKFLOW_STATUS_IN_PROGRESS;

            if (! $employee->canTransitionOnboardingWorkflowTo($targetWorkflowStatus)) {
                throw ValidationException::withMessages([
                    'onboarding_workflow_status' => __('Cannot submit: onboarding workflow is not in an expected state for this action'),
                ]);
            }

            $employee->transitionOnboardingWorkflowTo($targetWorkflowStatus);

            $submission->refresh();

            return $submission;
        });

        $submission->load('formTemplate');

        if ($status === 'submitted') {
            app(OnboardingCompletionService::class)->checkCompletion($employee);
        }

        $this->prepareLocalizedSubmissionTemplate($submission, $this->resolveTemplateLocale($request));

        return response()->json([
            'data' => new OnboardingFormSubmissionResource($submission),
        ]);
    }

    /**
     * @param  array<string, mixed>  $storedFormData
     * @param  array<string, mixed>  $incomingFormData
     * @return array<string, mixed>
     */
    private function mergeSubmissionFormData(array $storedFormData, array $incomingFormData): array
    {
        $mergedFormData = $storedFormData;

        foreach ($incomingFormData as $key => $value) {
            if ($value === null) {
                unset($mergedFormData[$key]);

                continue;
            }

            $existingValue = $mergedFormData[$key] ?? null;

            if (
                is_array($value)
                && is_array($existingValue)
                && ! array_is_list($value)
                && ! array_is_list($existingValue)
            ) {
                /** @var array<string, mixed> $existingNestedValue */
                $existingNestedValue = $existingValue;

                /** @var array<string, mixed> $incomingNestedValue */
                $incomingNestedValue = $value;

                $mergedFormData[$key] = $this->mergeSubmissionFormData(
                    $existingNestedValue,
                    $incomingNestedValue,
                );

                continue;
            }

            $mergedFormData[$key] = $value;
        }

        return $mergedFormData;
    }

    /**
     * Upload a file for an editable onboarding submission.
     *
     * POST /v1/onboarding/submissions/{submission}/files
     */
    public function uploadSubmissionFile(UploadOnboardingSubmissionFileRequest $request, OnboardingFormSubmission $submission): JsonResponse
    {
        $this->authorize('uploadFile', $submission);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        /** @var \App\Models\User $user */
        $user = $request->user();

        $uploadResult = $this->submissionFileUploadService->upload(
            $file,
            $submission,
            $user->id,
            $validated,
        );
        if ($uploadResult['conflict']) {
            return response()->json([
                'message' => __('The upload idempotency key was already used for a different upload.'),
            ], Response::HTTP_CONFLICT);
        }

        $uploadedFile = $uploadResult['file'];
        if ($uploadedFile === null) {
            throw new \LogicException('Onboarding upload completed without a file result');
        }

        return $this->submissionFileUploadResponse(
            $uploadedFile,
            $uploadResult['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    private function submissionFileUploadResponse(
        OnboardingSubmissionFile $uploadedFile,
        int $status
    ): JsonResponse {
        return response()->json([
            'data' => [
                'id' => $uploadedFile->id,
                'filename' => $uploadedFile->file_name,
            ],
        ], $status);
    }

    /**
     * Delete a previously uploaded file for an editable onboarding submission.
     *
     * DELETE /v1/onboarding/submissions/{submission}/files/{file}
     */
    public function deleteSubmissionFile(Request $request, OnboardingFormSubmission $submission, string $file): JsonResponse|Response
    {
        $this->authorize('uploadFile', $submission);

        if (! in_array($submission->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => __('Files can only be deleted while the onboarding submission is editable'),
            ]);
        }

        $submissionFile = OnboardingSubmissionFile::query()
            ->where('onboarding_form_submission_id', $submission->id)
            ->whereKey($file)
            ->first();

        if ($submissionFile === null) {
            return response()->json([
                'message' => __('File not found for this onboarding submission.'),
            ], Response::HTTP_NOT_FOUND);
        }

        $filePath = $submissionFile->file_path;

        DB::transaction(function () use ($submissionFile, $filePath): void {
            $submissionFile->delete();

            DB::afterCommit(static function () use ($filePath): void {
                Storage::disk('local')->delete($filePath);
            });
        });

        return response()->noContent();
    }

    /**
     * Approve onboarding form submission (HR only).
     *
     * POST /v1/onboarding-review/submissions/{submission}/approve
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

        DB::transaction(function () use ($submission, $user): void {
            $submission->update([
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            $this->onboardingResidentialAddressHistorySyncService->syncFromApprovedSubmission($submission);
            $this->onboardingTaxIdentificationSyncService->syncFromApprovedSubmission($submission);

            /** @var Employee $employee */
            $employee = $submission->employee()->firstOrFail();
            $this->onboardingCompletionService->checkCompletion($employee);
        });

        /** @var OnboardingFormSubmission $fresh */
        $fresh = $submission->fresh();
        $fresh->load(['formTemplate', 'reviewer']);

        $this->prepareLocalizedSubmissionTemplate($fresh, $this->resolveTemplateLocale($request));

        return response()->json([
            'data' => new OnboardingFormSubmissionResource($fresh),
        ]);
    }

    /**
     * Reject onboarding form submission (HR only).
     *
     * POST /v1/onboarding-review/submissions/{submission}/reject
     */
    public function rejectSubmission(Request $request, OnboardingFormSubmission $submission): JsonResponse
    {
        $this->authorize('reject', $submission);

        if ($submission->status !== 'submitted') {
            return response()->json([
                'message' => __('Only submitted forms can be rejected'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        DB::transaction(function () use ($submission, $user, $validated): void {
            $submission->update([
                'status' => 'rejected',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $validated['reason'],
            ]);

            /** @var Employee $employee */
            $employee = $submission->employee()->firstOrFail();

            if (! $employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_CHANGES_REQUESTED)) {
                throw ValidationException::withMessages([
                    'onboarding_workflow_status' => __('Cannot reject: employee workflow is not in submitted_for_review state'),
                ]);
            }

            $employee->transitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_CHANGES_REQUESTED);
        });

        /** @var OnboardingFormSubmission $fresh */
        $fresh = $submission->fresh();
        $fresh->load(['formTemplate', 'reviewer']);

        $this->prepareLocalizedSubmissionTemplate($fresh, $this->resolveTemplateLocale($request));

        return response()->json([
            'data' => new OnboardingFormSubmissionResource($fresh),
        ]);
    }

    /**
     * @param  iterable<OnboardingFormTemplate>  $templates
     */
    private function prepareLocalizedTemplates(iterable $templates, string $locale): void
    {
        foreach ($templates as $template) {
            if (! $template instanceof OnboardingFormTemplate) {
                continue;
            }

            $this->prepareLocalizedTemplate($template, $locale);
        }
    }

    /**
     * @param  iterable<OnboardingFormSubmission>  $submissions
     */
    private function prepareLocalizedSubmissionTemplates(iterable $submissions, string $locale): void
    {
        foreach ($submissions as $submission) {
            if (! $submission instanceof OnboardingFormSubmission) {
                continue;
            }

            $this->prepareLocalizedSubmissionTemplate($submission, $locale);
        }
    }

    private function prepareLocalizedSubmissionTemplate(OnboardingFormSubmission $submission, string $locale): void
    {
        if (! $submission->relationLoaded('formTemplate')) {
            return;
        }

        $template = $submission->formTemplate;

        if (! $template instanceof OnboardingFormTemplate) {
            return;
        }

        $this->prepareLocalizedTemplate($template, $locale);
    }

    private function prepareLocalizedTemplate(OnboardingFormTemplate $template, string $locale): void
    {
        $template->setAttribute(
            OnboardingFormTemplate::LOCALIZED_TEMPLATE_ATTRIBUTE,
            $this->onboardingSchemaLocalizationService->localizeTemplate($template, $locale),
        );
    }

    private function resolveTemplateLocale(Request $request): string
    {
        $preferredLocale = $request->user()?->preferred_locale;

        if (is_string($preferredLocale) && in_array($preferredLocale, OnboardingSchemaLocalizationService::SUPPORTED_LOCALES, true)) {
            return $preferredLocale;
        }

        $requestLocale = $request->getPreferredLanguage(OnboardingSchemaLocalizationService::SUPPORTED_LOCALES);

        return is_string($requestLocale) ? $requestLocale : OnboardingSchemaLocalizationService::DEFAULT_LOCALE;
    }

    private function resolveCountryDisplayName(string $countryCode, string $locale): string
    {
        $specialLabels = [
            'XK' => [
                'de' => 'Kosovo',
                'en' => 'Kosovo',
            ],
            'XA' => [
                'de' => 'Staatenlos',
                'en' => 'Stateless',
            ],
        ];

        if (isset($specialLabels[$countryCode][$locale])) {
            return $specialLabels[$countryCode][$locale];
        }

        if (! class_exists(\Locale::class)) {
            return $countryCode;
        }

        $displayName = \Locale::getDisplayRegion('-'.$countryCode, $locale);

        if (! is_string($displayName)) {
            return $countryCode;
        }

        $trimmed = trim($displayName);

        return $trimmed !== '' ? $trimmed : $countryCode;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @param  array<string, mixed>  $schema
     */
    private function assertUploadDecisionRequirements(
        array $formData,
        array $schema,
        ?OnboardingFormSubmission $submission,
        Employee $employee,
    ): void {
        if (! $this->schemaDefinesProperty($schema, 'nationalities')) {
            return;
        }

        $primaryNationalityCode = $this->getPrimaryNationalityCode(
            $formData['nationalities'] ?? null
        );
        if ($primaryNationalityCode === null) {
            return;
        }

        if ($this->schemaDefinesProperty($schema, self::ID_DOCUMENT_UPLOAD_NOW_FIELD)) {
            $identityUploadNow = $this->normalizeYesNo(
                $formData[self::ID_DOCUMENT_UPLOAD_NOW_FIELD] ?? null
            );
            if ($identityUploadNow === null) {
                throw ValidationException::withMessages([
                    self::ID_DOCUMENT_UPLOAD_NOW_FIELD => [__('Please choose whether you want to upload your identity document now.')],
                ]);
            }

            if ($identityUploadNow !== 'yes') {
                $identityUploadNow = null;
            }
        } else {
            $identityUploadNow = null;
        }

        if ($identityUploadNow === 'yes') {
            if ($primaryNationalityCode === 'DE') {
                $documentKind = $this->normalizedNonEmptyString(
                    $formData[self::ID_DOCUMENT_KIND_FIELD] ?? null
                );
                if (! in_array($documentKind, ['id_card', 'passport'], true)) {
                    throw ValidationException::withMessages([
                        self::ID_DOCUMENT_KIND_FIELD => [__('Please choose which identity document you are uploading.')],
                    ]);
                }
            }

            if (
                $submission !== null
                && ! $this->hasUploadedSubmissionFile($submission, [
                    'identity_document',
                    'identity_document_front',
                    'identity_document_back',
                ])
            ) {
                throw ValidationException::withMessages([
                    self::ID_DOCUMENT_UPLOAD_NOW_FIELD => [__('Please upload at least one identity document file before continuing.')],
                ]);
            }
        }

        if (in_array($primaryNationalityCode, self::RESIDENCE_TITLE_EXEMPT_COUNTRY_CODES, true)) {
            return;
        }

        if (! $this->shouldAskEmploymentQuestion($formData, $employee)) {
            return;
        }

        $employmentAllowed = strtolower(
            $this->normalizedNonEmptyString(
                $formData[self::RESIDENCE_PERMIT_EMPLOYMENT_ALLOWED_FIELD] ?? null
            ) ?? ''
        );
        if ($employmentAllowed !== 'yes') {
            return;
        }

        if (! $this->schemaDefinesProperty($schema, self::RESIDENCE_PERMIT_UPLOAD_NOW_FIELD)) {
            return;
        }

        $residenceUploadNow = $this->normalizeYesNo(
            $formData[self::RESIDENCE_PERMIT_UPLOAD_NOW_FIELD] ?? null
        );
        if ($residenceUploadNow === null) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_UPLOAD_NOW_FIELD => [__('Please choose whether you want to upload your residence title now.')],
            ]);
        }

        if (
            $residenceUploadNow === 'yes'
            && $submission !== null
            && ! $this->hasUploadedSubmissionFile($submission, [
                'residence_permit_front',
                'residence_permit_back',
            ])
        ) {
            throw ValidationException::withMessages([
                self::RESIDENCE_PERMIT_UPLOAD_NOW_FIELD => [__('Please upload at least one residence title file before continuing.')],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function schemaDefinesProperty(array $schema, string $field): bool
    {
        $properties = $schema['properties'] ?? null;

        return is_array($properties) && array_key_exists($field, $properties);
    }

    /**
     * @param  array<int, string>  $subtypes
     */
    private function hasUploadedSubmissionFile(
        ?OnboardingFormSubmission $submission,
        array $subtypes
    ): bool {
        if ($submission === null) {
            return false;
        }

        return OnboardingSubmissionFile::query()
            ->where('onboarding_form_submission_id', $submission->id)
            ->where('document_type', 'id_document')
            ->whereIn('document_subtype', $subtypes)
            ->exists();
    }

    private function getPrimaryNationalityCode(mixed $nationalities): ?string
    {
        if (! is_array($nationalities)) {
            return null;
        }

        $first = $nationalities[0] ?? null;
        if (! is_string($first) && ! is_int($first)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $first));

        return preg_match('/^[A-Z]{2}$/', $normalized) === 1 ? $normalized : null;
    }

    private function normalizeYesNo(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['yes', 'no'], true) ? $normalized : null;
    }

    private function normalizedNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Normalize DOB strings from HR data / onboarding input to a canonical Y-m-d value.
     */
    private function normalizeDateOfBirthForIdentityCheck(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($trimmed))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function shouldAskEmploymentQuestion(
        array $formData,
        Employee $employee,
    ): bool {
        $title = $this->normalizedNonEmptyString(
            $formData[self::RESIDENCE_PERMIT_TITLE_FIELD] ?? null
        );
        if ($title === null) {
            return false;
        }

        if ($this->isResidencePermitUnlimited($formData)) {
            return true;
        }

        $contractStartDate = $this->resolveContractStartDate($formData, $employee);
        if ($contractStartDate === null) {
            return false;
        }

        $expiry = $this->normalizedNonEmptyString(
            $formData[self::RESIDENCE_PERMIT_EXPIRY_FIELD] ?? null
        );
        if (
            $expiry === null
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) !== 1
            || $expiry <= now()->toDateString()
        ) {
            return false;
        }

        return $expiry > $contractStartDate;
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function resolveContractStartDate(
        array $formData,
        Employee $employee,
    ): ?string {
        $fromForm = $this->normalizedNonEmptyString(
            $formData[self::CONTRACT_START_DATE_FIELD] ?? null
        );
        if ($fromForm !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromForm) === 1) {
            return $fromForm;
        }

        $fromEmployee = $employee->contract_start_date?->toDateString();

        return is_string($fromEmployee) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromEmployee) === 1
            ? $fromEmployee
            : null;
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function isResidencePermitUnlimited(array $formData): bool
    {
        $value = $formData[self::RESIDENCE_PERMIT_UNLIMITED_FIELD] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    /**
     * Confirm a pre-contract onboarding dossier after HR/compliance review.
     *
     * POST /v1/onboarding-review/employees/{employee}/confirm
     */
    public function confirmEmployeeOnboarding(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('confirmOnboarding', $employee);

        /** @var array{notes?: string|null} $validated */
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $notes = $validated['notes'] ?? null;

        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var Employee $freshEmployee */
        $freshEmployee = DB::transaction(function () use ($employee, $notes, $user): Employee {
            /** @var Employee $lockedEmployee */
            $lockedEmployee = Employee::query()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fromWorkflowStatus = $lockedEmployee->resolveOnboardingWorkflowStatus();

            if ($lockedEmployee->status !== Employee::STATUS_PRE_CONTRACT) {
                throw ValidationException::withMessages([
                    'status' => __('Only pre-contract employees can be confirmed for onboarding'),
                ]);
            }

            if ($fromWorkflowStatus !== Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW) {
                throw ValidationException::withMessages([
                    'onboarding_workflow_status' => __('Employee onboarding dossier must be submitted for review before confirmation'),
                ]);
            }

            if (! $lockedEmployee->onboarding_completed) {
                throw ValidationException::withMessages([
                    'onboarding_completed' => __('Employee onboarding dossier must be complete before confirmation'),
                ]);
            }

            $lockedEmployee->transitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED);
            $lockedEmployee->refresh();

            $lockedEmployee->syncActivationReadinessWorkflow();
            $lockedEmployee->refresh();

            $toWorkflowStatus = $lockedEmployee->resolveOnboardingWorkflowStatus();

            if ($toWorkflowStatus !== $fromWorkflowStatus) {
                activity('employee-onboarding')
                    ->causedBy($user)
                    ->performedOn($lockedEmployee)
                    ->event('onboarding_contract_confirmed')
                    ->withProperties([
                        'action' => 'onboarding_contract_confirmed',
                        'notes' => $notes,
                        'confirmed_at' => \App\Support\ApiTimestamp::format(now()),
                        'from_workflow_status' => $fromWorkflowStatus,
                        'to_workflow_status' => $toWorkflowStatus,
                        'contract_start_date' => $lockedEmployee->contract_start_date?->toDateString(),
                    ])
                    ->log('HR confirmed onboarding dossier and contract state');
            }

            return $lockedEmployee;
        });

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
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
