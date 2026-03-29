<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Employee model for HR management.
 *
 * Manages employee master data with lifecycle state machine, encryption, and user account integration.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string $employee_number
 * @property string|null $bwr_id Bewacherregister-ID
 * @property string $bwr_status not_registered|pending|active|suspended|revoked
 * @property ?\Illuminate\Support\Carbon $bwr_registered_at
 * @property ?\Illuminate\Support\Carbon $bwr_submission_date
 * @property string|null $bwr_notes
 * @property string $first_name_enc Encrypted first name
 * @property string|null $first_name_idx Blind index for first name search
 * @property string $last_name_enc Encrypted last name
 * @property string|null $last_name_idx Blind index for last name search
 * @property string|null $gender male|female|diverse
 * @property string|null $birth_name_enc Encrypted birth name
 * @property array<int, string>|null $previous_names Array of previous names
 * @property string|null $date_of_birth_enc Encrypted date of birth (text, not date)
 * @property string|null $date_of_birth_idx Blind index for date of birth
 * @property string|null $birth_city
 * @property string|null $birth_country ISO 3166-1 alpha-2
 * @property string|null $birth_state
 * @property array<int, string>|null $nationalities Array of ISO 3166-1 alpha-2 codes
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_street_enc Encrypted street name
 * @property string|null $address_house_number_enc Encrypted house number
 * @property string|null $address_postal_code_enc Encrypted postal code
 * @property string|null $address_city_enc Encrypted city
 * @property string|null $address_supplement_enc Encrypted address supplement
 * @property string|null $address_country ISO 3166-1 alpha-2
 * @property string|null $address_state
 * @property array<int, array{from: string, to: string, street: string, house_number: string, postal_code: string, city: string, country: string, state: ?string}>|null $address_history Array of address objects (5 years)
 * @property array<int, string>|null $intended_activities Array of activity codes
 * @property string|null $id_document_type id_card|passport|residence_permit
 * @property string|null $id_document_number_enc Encrypted document number
 * @property ?\Illuminate\Support\Carbon $id_document_expiry
 * @property string|null $id_document_copy_path Storage path (auto-deleted on BWR approval)
 * @property ?\Illuminate\Support\Carbon $id_document_copy_deleted_at
 * @property string|null $photo_path
 * @property string|null $tax_id_enc Encrypted tax ID (Steueridentifikationsnummer)
 * @property string|null $social_security_number_enc Encrypted social security number (Sozialversicherungsnummer)
 * @property string $status applicant|pre_contract|active|on_leave|terminated
 * @property ?\Illuminate\Support\Carbon $hire_date
 * @property ?\Illuminate\Support\Carbon $contract_start_date
 * @property ?\Illuminate\Support\Carbon $termination_date
 * @property ?\Illuminate\Support\Carbon $last_working_day
 * @property string $contract_type full_time|part_time|minijob|freelance
 * @property float|null $weekly_hours Weekly hours for part-time/hourly contracts
 * @property float|null $monthly_hours Monthly hours (e.g., 173h/month standard in security)
 * @property string|null $hourly_rate_enc Encrypted hourly rate
 * @property string|null $health_insurance_type public|private|foreign
 * @property string|null $health_insurance_provider
 * @property string|null $health_insurance_number
 * @property string|null $sachkunde_type
 * @property string|null $sachkunde_certificate
 * @property ?\Illuminate\Support\Carbon $sachkunde_expiry
 * @property string|null $sachkunde_ihk_number IHK identification number
 * @property ?\Illuminate\Support\Carbon $sachkunde_exam_date
 * @property ?\Illuminate\Support\Carbon $sachkunde_issued_date
 * @property string $work_permit_type unlimited|limited|none
 * @property string|null $work_permit_number
 * @property ?\Illuminate\Support\Carbon $work_permit_expiry
 * @property string $residence_permit_type unlimited|limited|none
 * @property string|null $residence_permit_number
 * @property ?\Illuminate\Support\Carbon $residence_permit_expiry
 * @property string|null $criminal_record_status valid|expired|pending
 * @property ?\Illuminate\Support\Carbon $criminal_record_check_date
 * @property string|null $user_id
 * @property bool $user_account_active
 * @property ?\Illuminate\Support\Carbon $user_account_activated_at
 * @property ?\Illuminate\Support\Carbon $user_account_deactivated_at
 * @property bool $onboarding_completed
 * @property array<string, mixed>|null $onboarding_steps
 * @property ?\Illuminate\Support\Carbon $onboarding_started_at
 * @property ?\Illuminate\Support\Carbon $onboarding_completed_at
 * @property string|null $onboarding_workflow_status invited|account_initialized|in_progress|submitted_for_review|changes_requested|contract_confirmed|ready_for_activation|active
 * @property string $onboarding_invitation_status not_requested|sent|created_not_sent|failed
 * @property ?\Illuminate\Support\Carbon $onboarding_invitation_requested_at
 * @property ?\Illuminate\Support\Carbon $onboarding_invitation_token_created_at
 * @property ?\Illuminate\Support\Carbon $onboarding_invitation_mail_sent_at
 * @property ?\Illuminate\Support\Carbon $onboarding_invitation_mail_failed_at
 * @property string|null $onboarding_invitation_failure_reason
 * @property string|null $organizational_unit_id
 * @property string|null $position Job title/role (e.g., 'Objektleiter Flughafen Berlin')
 * @property int $management_level Management level: 0=non-management, 1=CEO/highest, 2-255=lower levels
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 * @property-read string $first_name Decrypted first name
 * @property-read string $last_name Decrypted last name
 * @property-read string|null $birth_name Decrypted birth name
 * @property-read string|null $date_of_birth Decrypted date of birth (string, not Carbon)
 * @property-read string|null $address_street Decrypted street
 * @property-read string|null $address_house_number Decrypted house number
 * @property-read string|null $address_postal_code Decrypted postal code
 * @property-read string|null $address_city Decrypted city
 * @property-read string|null $address_supplement Decrypted address supplement
 * @property-read string|null $id_document_number Decrypted document number
 * @property-read float|null $hourly_rate Decrypted hourly rate
 * @property-read string|null $tax_id Decrypted tax ID
 * @property-read string|null $social_security_number Decrypted social security number
 * @property-read string $full_name Full name (first + last)
 * @property-read TenantKey $tenant
 * @property-read User|null $user
 * @property-read OrganizationalUnit|null $organizationalUnit
 * @property-read Collection<int, EmployeeQualification> $employeeQualifications
 * @property-read Collection<int, Qualification> $qualifications
 * @property-read Collection<int, EmployeeDocument> $documents
 * @property-read Collection<int, OnboardingFormSubmission> $onboardingSubmissions
 */
class Employee extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, LogsActivity, SoftDeletes {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** Status constants */
    public const STATUS_APPLICANT = 'applicant';

    public const STATUS_PRE_CONTRACT = 'pre_contract';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_TERMINATED = 'terminated';

    /** @var list<string> */
    public const VALID_STATUSES = [
        self::STATUS_APPLICANT,
        self::STATUS_PRE_CONTRACT,
        self::STATUS_ACTIVE,
        self::STATUS_ON_LEAVE,
        self::STATUS_TERMINATED,
    ];

    /** @var list<string> */
    public const INVITABLE_STATUSES = [
        self::STATUS_PRE_CONTRACT,
    ];

    public const INVITATION_STATUS_NOT_REQUESTED = 'not_requested';

    public const INVITATION_STATUS_SENT = 'sent';

    public const INVITATION_STATUS_CREATED_NOT_SENT = 'created_not_sent';

    public const INVITATION_STATUS_FAILED = 'failed';

    public const WORKFLOW_STATUS_INVITED = 'invited';

    public const WORKFLOW_STATUS_ACCOUNT_INITIALIZED = 'account_initialized';

    public const WORKFLOW_STATUS_IN_PROGRESS = 'in_progress';

    public const WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW = 'submitted_for_review';

    public const WORKFLOW_STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const WORKFLOW_STATUS_CONTRACT_CONFIRMED = 'contract_confirmed';

    public const WORKFLOW_STATUS_READY_FOR_ACTIVATION = 'ready_for_activation';

    public const WORKFLOW_STATUS_ACTIVE = 'active';

    /** @var list<string> */
    public const VALID_WORKFLOW_STATUSES = [
        self::WORKFLOW_STATUS_INVITED,
        self::WORKFLOW_STATUS_ACCOUNT_INITIALIZED,
        self::WORKFLOW_STATUS_IN_PROGRESS,
        self::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
        self::WORKFLOW_STATUS_CHANGES_REQUESTED,
        self::WORKFLOW_STATUS_CONTRACT_CONFIRMED,
        self::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        self::WORKFLOW_STATUS_ACTIVE,
    ];

    /**
     * Temporary storage for GDPR changed fields during model lifecycle.
     * Maps spl_object_id (as string) to array of changed field names.
     *
     * @var array<int|string, string[]>
     */
    private static array $gdprChangedFields = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'employee_number',
        // BWR Fields
        'bwr_id',
        'bwr_status',
        'bwr_registered_at',
        'bwr_submission_date',
        'bwr_notes',
        // Personal Identity
        'first_name', // plaintext field → triggers mutator → sets first_name_enc → triggers cast
        'first_name_enc',
        'first_name_idx',
        'last_name', // plaintext field → triggers mutator → sets last_name_enc → triggers cast
        'last_name_enc',
        'last_name_idx',
        'gender',
        'birth_name', // plaintext field → triggers mutator → sets birth_name_enc → triggers cast
        'birth_name_enc',
        'previous_names',
        'date_of_birth', // plaintext field → triggers mutator → sets date_of_birth_enc → triggers cast
        'date_of_birth_enc',
        'date_of_birth_idx',
        'birth_city',
        'birth_country',
        'birth_state',
        'nationalities',
        // Contact
        'email',
        'phone',
        // Structured Address
        'address_street', // plaintext → address_street_enc
        'address_street_enc',
        'address_house_number', // plaintext → address_house_number_enc
        'address_house_number_enc',
        'address_postal_code', // plaintext → address_postal_code_enc
        'address_postal_code_enc',
        'address_city', // plaintext → address_city_enc
        'address_city_enc',
        'address_supplement', // plaintext → address_supplement_enc
        'address_supplement_enc',
        'address_country',
        'address_state',
        'address_history',
        // Intended Activities
        'intended_activities',
        // ID Document
        'id_document_type',
        'id_document_number', // plaintext → id_document_number_enc
        'id_document_number_enc',
        'id_document_expiry',
        // NOTE: id_document_copy_path is NOT fillable (security risk - prevents arbitrary file deletion)
        // This field is set server-side during file upload via dedicated upload endpoint
        'id_document_copy_deleted_at',
        'photo_path',
        // Tax & SSN
        'tax_id', // plaintext field → triggers mutator → sets tax_id_enc → triggers cast
        'tax_id_enc',
        'social_security_number', // plaintext field → triggers mutator → sets social_security_number_enc → triggers cast
        'social_security_number_enc',
        // Employment Status
        'status',
        'hire_date',
        'contract_start_date',
        'termination_date',
        'last_working_day',
        'employment_end_date',
        'retention_period_end',
        'contract_type',
        'weekly_hours',
        'monthly_hours',
        'hourly_rate', // plaintext field → triggers mutator → sets hourly_rate_enc → triggers cast
        'hourly_rate_enc',
        'health_insurance_type',
        'health_insurance_provider',
        'health_insurance_number',
        'sachkunde_type',
        'sachkunde_certificate',
        'sachkunde_expiry',
        'sachkunde_ihk_number',
        'sachkunde_exam_date',
        'sachkunde_issued_date',
        'work_permit_type',
        'work_permit_number',
        'work_permit_expiry',
        'residence_permit_type',
        'residence_permit_number',
        'residence_permit_expiry',
        'criminal_record_status',
        'criminal_record_check_date',
        'user_id',
        'user_account_active',
        'user_account_activated_at',
        'user_account_deactivated_at',
        'onboarding_completed',
        'onboarding_steps',
        'onboarding_started_at',
        'onboarding_completed_at',
        'onboarding_workflow_status',
        'onboarding_invitation_status',
        'onboarding_invitation_requested_at',
        'onboarding_invitation_token_created_at',
        'onboarding_invitation_mail_sent_at',
        'onboarding_invitation_mail_failed_at',
        'onboarding_invitation_failure_reason',
        'organizational_unit_id',
        'position',
        'management_level',
    ];

    /**
     * The attributes that should be hidden for arrays and JSON serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'first_name_enc',
        'first_name_idx',
        'last_name_enc',
        'last_name_idx',
        'birth_name_enc',
        'date_of_birth_enc',
        'date_of_birth_idx',
        'address_street_enc',
        'address_house_number_enc',
        'address_postal_code_enc',
        'address_city_enc',
        'address_supplement_enc',
        'id_document_number_enc',
        'hourly_rate_enc',
        'tax_id_enc',
        'social_security_number_enc',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted fields
            'first_name_enc' => \App\Casts\EncryptedWithDek::class,
            'last_name_enc' => \App\Casts\EncryptedWithDek::class,
            'birth_name_enc' => \App\Casts\EncryptedWithDek::class,
            'date_of_birth_enc' => \App\Casts\EncryptedWithDek::class,
            'address_street_enc' => \App\Casts\EncryptedWithDek::class,
            'address_house_number_enc' => \App\Casts\EncryptedWithDek::class,
            'address_postal_code_enc' => \App\Casts\EncryptedWithDek::class,
            'address_city_enc' => \App\Casts\EncryptedWithDek::class,
            'address_supplement_enc' => \App\Casts\EncryptedWithDek::class,
            'id_document_number_enc' => \App\Casts\EncryptedWithDek::class,
            'hourly_rate_enc' => \App\Casts\EncryptedWithDek::class,
            'tax_id_enc' => \App\Casts\EncryptedWithDek::class,
            'social_security_number_enc' => \App\Casts\EncryptedWithDek::class,
            // JSON arrays
            'previous_names' => 'array',
            'nationalities' => 'array',
            'address_history' => 'array',
            'intended_activities' => 'array',
            // Dates
            'bwr_registered_at' => 'datetime',
            'bwr_submission_date' => 'date',
            'hire_date' => 'date',
            'contract_start_date' => 'date',
            'termination_date' => 'date',
            'last_working_day' => 'date',
            'employment_end_date' => 'date',
            'retention_period_end' => 'date',
            // Note: sachkunde_expiry removed - Sachkunde never expires (valid for life)!
            'sachkunde_exam_date' => 'date',
            'sachkunde_issued_date' => 'date',
            'id_document_expiry' => 'date',
            'id_document_copy_deleted_at' => 'datetime',
            'work_permit_expiry' => 'date',
            'residence_permit_expiry' => 'date',
            'criminal_record_check_date' => 'date',
            // Decimals
            'weekly_hours' => 'decimal:2',
            'monthly_hours' => 'decimal:2',
            // Booleans
            'user_account_active' => 'boolean',
            'onboarding_completed' => 'boolean',
            // Datetimes
            'user_account_activated_at' => 'datetime',
            'user_account_deactivated_at' => 'datetime',
            'onboarding_started_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'onboarding_workflow_status' => 'string',
            'onboarding_invitation_requested_at' => 'datetime',
            'onboarding_invitation_token_created_at' => 'datetime',
            'onboarding_invitation_mail_sent_at' => 'datetime',
            'onboarding_invitation_mail_failed_at' => 'datetime',
            // Arrays
            'onboarding_steps' => 'array',
        ];
    }

    /**
     * Configure activity logging.
     *
     * Logs employee changes (3-year retention: employee_changes).
     *
     * DSGVO-KONFORME LOGGING-STRATEGIE:
     *
     * MIT Werten geloggt (rechtlich/compliance notwendig):
     * - employee_number, status, position, management_level
     * - contract_type, hire_date, contract_start_date, termination_date, last_working_day
     * - user_account_active, organizational_unit_id
     *
     * OHNE Werte geloggt (personenbezogene Daten - DSGVO Art. 5 Abs. 1 lit. c):
     * - first_name, last_name (via booted() Event)
     * - email, phone (via booted() Event)
     * - Alle verschlüsselten Felder (date_of_birth, address, hourly_rate, tax_id, ssn)
     *
     * Grund: Unveränderlicher Audit Log verhindert "Recht auf Vergessenwerden" (Art. 17 DSGVO).
     * Wir dokumentieren DASS sich etwas änderte, NICHT die Werte selbst.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'employee_number',
                // email and phone are logged via GDPR-compliant manual observer (see booted() method)
                'status',
                'position',
                'management_level',
                'contract_type',
                'hire_date',
                'contract_start_date',
                'termination_date',
                'last_working_day',
                'user_account_active',
                'organizational_unit_id',
            ])
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('employee_changes');
    }

    /**
     * Check if a field's decrypted value actually changed (not just re-encrypted with new nonce).
     *
     * IMPORTANT: Encrypted fields (_enc) are ALWAYS marked as dirty due to new nonce generation.
     * This method compares DECRYPTED values to detect actual changes.
     *
     * @param  string  $field  The field name to check (e.g., 'first_name', not 'first_name_enc')
     * @return bool True if the decrypted value actually changed
     */
    protected function hasActuallyChanged(string $field): bool
    {
        // Get original (from DB before changes) - will be decrypted by accessor
        $original = $this->getOriginal($field);
        // Get current (in-memory, potentially modified)
        $current = $this->getAttribute($field);

        // Both null = no change
        if ($original === null && $current === null) {
            return false;
        }

        // One is null, other isn't = changed
        if ($original === null || $current === null) {
            return true;
        }

        // Compare decrypted values
        return $original !== $current;
    }

    /**
     * The "booted" method of the model.
     *
     * Registers event listeners for DSGVO-compliant logging of sensitive personal data.
     * Documents THAT a change occurred without storing the actual values.
     *
     * IMPORTANT: Encrypted fields (_enc) are ALWAYS marked as dirty due to new nonce generation.
     * We must compare DECRYPTED values to detect actual changes, not the encrypted columns.
     *
     * Uses 'updating' event to capture changed fields, then 'updated' event to log them.
     * This ensures the Spatie LogsActivity trait's log is created first, then our GDPR log,
     * maintaining proper sequential order for the hash chain.
     */
    protected static function booted(): void
    {
        // Track changed sensitive fields during 'updating' (before save)
        static::updating(function (Employee $employee) {
            $changedFields = [];
            // Name changes (check decrypted values via accessors: first_name, last_name)
            if ($employee->isDirty('first_name_enc') && $employee->hasActuallyChanged('first_name')) {
                $changedFields[] = 'first_name';
            }
            if ($employee->isDirty('last_name_enc') && $employee->hasActuallyChanged('last_name')) {
                $changedFields[] = 'last_name';
            }

            // Contact information (personal data)
            if ($employee->isDirty('email')) {
                $changedFields[] = 'email';
            }
            if ($employee->isDirty('phone')) {
                $changedFields[] = 'phone';
            }

            // Highly sensitive encrypted data (check decrypted values)
            if ($employee->isDirty('date_of_birth_enc') && $employee->hasActuallyChanged('date_of_birth')) {
                $changedFields[] = 'date_of_birth';
            }
            // New BewachV §16 encrypted fields
            if ($employee->isDirty('birth_name_enc') && $employee->hasActuallyChanged('birth_name')) {
                $changedFields[] = 'birth_name';
            }
            if ($employee->isDirty('address_street_enc') && $employee->hasActuallyChanged('address_street')) {
                $changedFields[] = 'address_street';
            }
            if ($employee->isDirty('address_house_number_enc') && $employee->hasActuallyChanged('address_house_number')) {
                $changedFields[] = 'address_house_number';
            }
            if ($employee->isDirty('address_postal_code_enc') && $employee->hasActuallyChanged('address_postal_code')) {
                $changedFields[] = 'address_postal_code';
            }
            if ($employee->isDirty('address_city_enc') && $employee->hasActuallyChanged('address_city')) {
                $changedFields[] = 'address_city';
            }
            if ($employee->isDirty('address_supplement_enc') && $employee->hasActuallyChanged('address_supplement')) {
                $changedFields[] = 'address_supplement';
            }
            if ($employee->isDirty('id_document_number_enc') && $employee->hasActuallyChanged('id_document_number')) {
                $changedFields[] = 'id_document_number';
            }
            if ($employee->isDirty('hourly_rate_enc') && $employee->hasActuallyChanged('hourly_rate')) {
                $changedFields[] = 'hourly_rate';
            }
            if ($employee->isDirty('tax_id_enc') && $employee->hasActuallyChanged('tax_id')) {
                $changedFields[] = 'tax_id';
            }
            if ($employee->isDirty('social_security_number_enc') && $employee->hasActuallyChanged('social_security_number')) {
                $changedFields[] = 'social_security_number';
            }

            // Store in static array for use in 'updated' event
            // Use spl_object_id() instead of $employee->id for robust tracking (works even with unsaved models)
            $key = (string) spl_object_id($employee);
            self::$gdprChangedFields[$key] = $changedFields;
        });

        // Create GDPR log AFTER save (after Spatie LogsActivity has created its log)
        static::updated(function (Employee $employee) {
            $key = (string) spl_object_id($employee);
            $changedFields = self::$gdprChangedFields[$key] ?? [];

            // Log if any sensitive fields changed
            if (! empty($changedFields) && Auth::check()) {
                activity('employee_changes')
                    ->performedOn($employee)
                    ->causedBy(Auth::user())
                    ->withProperties([
                        'changed_fields' => $changedFields,
                        'field_count' => count($changedFields),
                        'note' => 'Sensitive personal data changed - values not logged for GDPR compliance (Art. 5 Abs. 1 lit. c - Data Minimization)',
                    ])
                    ->log('Sensitive data changed (GDPR-compliant: no values stored)');
            }

            // Clean up temporary storage
            unset(self::$gdprChangedFields[$key]);
        });

        // Fallback cleanup to prevent memory leaks in long-running processes
        // (queue workers, Horizon) if 'updated' event doesn't fire
        static::saved(function (Employee $employee) {
            $key = (string) spl_object_id($employee);
            unset(self::$gdprChangedFields[$key]);
        });
    }

    // === RELATIONSHIPS ===

    /**
     * Get the tenant that owns this employee.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class);
    }

    /**
     * Get the user account linked to this employee.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the organizational unit this employee belongs to.
     *
     * @return BelongsTo<OrganizationalUnit, $this>
     */
    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    /**
     * Check if employee has a management level assigned.
     *
     * @return bool True if employee has management role (1-255), false for non-management (0)
     */
    public function hasManagementLevel(): bool
    {
        return $this->management_level > 0;
    }

    /**
     * Get all employee qualifications (pivot records).
     *
     * @return HasMany<EmployeeQualification, $this>
     */
    public function employeeQualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class);
    }

    /**
     * Get all qualifications for this employee.
     *
     * @return BelongsToMany<Qualification, $this>
     */
    public function qualifications(): BelongsToMany
    {
        return $this->belongsToMany(
            Qualification::class,
            'employee_qualifications'
        )->withPivot([
            'id',
            'obtained_date',
            'expiry_date',
            'certificate_number',
            'issuing_authority',
            'notes',
            'document_path',
            'status',
        ])->withTimestamps();
    }

    /**
     * Get all documents for this employee.
     *
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * Get all onboarding form submissions for this employee.
     *
     * @return HasMany<OnboardingFormSubmission, $this>
     */
    public function onboardingSubmissions(): HasMany
    {
        return $this->hasMany(OnboardingFormSubmission::class);
    }

    // === ACCESSORS (Decrypt encrypted fields) ===

    /**
     * Get decrypted first name (via EncryptedWithDek cast).
     */
    public function getFirstNameAttribute(): string
    {
        return $this->first_name_enc;
    }

    /**
     * Get decrypted last name (via EncryptedWithDek cast).
     */
    public function getLastNameAttribute(): string
    {
        return $this->last_name_enc;
    }

    /**
     * Get decrypted date of birth (via EncryptedWithDek cast, returns string not Carbon).
     */
    public function getDateOfBirthAttribute(): ?string
    {
        return $this->date_of_birth_enc;
    }

    /**
     * Get decrypted hourly rate (via EncryptedWithDek cast, returns float).
     */
    public function getHourlyRateAttribute(): ?float
    {
        $value = $this->hourly_rate_enc;

        return $value !== null ? (float) $value : null;
    }

    /**
     * Get decrypted tax ID (via EncryptedWithDek cast).
     */
    public function getTaxIdAttribute(): ?string
    {
        return $this->tax_id_enc;
    }

    /**
     * Get decrypted social security number (via EncryptedWithDek cast).
     */
    public function getSocialSecurityNumberAttribute(): ?string
    {
        return $this->social_security_number_enc;
    }

    /**
     * Get full name (first + last).
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // === MUTATORS (Direct assignment to _enc fields, encryption handled by Cast) ===

    /**
     * Set plaintext first name - Cast handles encryption.
     */
    public function setFirstNameAttribute(string $value): void
    {
        $this->first_name_enc = $value;
    }

    /**
     * Set plaintext last name - Cast handles encryption.
     */
    public function setLastNameAttribute(string $value): void
    {
        $this->last_name_enc = $value;
    }

    /**
     * Set plaintext date of birth - Cast handles encryption.
     */
    public function setDateOfBirthAttribute(?string $value): void
    {
        $this->date_of_birth_enc = $value;
    }

    /**
     * Set plaintext hourly rate - Cast handles encryption.
     */
    public function setHourlyRateAttribute(?float $value): void
    {
        $this->hourly_rate_enc = $value !== null ? (string) $value : null;
    }

    /**
     * Set plaintext tax ID - Cast handles encryption.
     */
    public function setTaxIdAttribute(?string $value): void
    {
        $this->tax_id_enc = $value;
    }

    /**
     * Set plaintext social security number - Cast handles encryption.
     */
    public function setSocialSecurityNumberAttribute(?string $value): void
    {
        $this->social_security_number_enc = $value;
    }

    // === NEW BEWACHV §16 ACCESSORS & MUTATORS ===

    /**
     * Get decrypted birth name (via EncryptedWithDek cast).
     */
    public function getBirthNameAttribute(): ?string
    {
        return $this->birth_name_enc;
    }

    /**
     * Set plaintext birth name - Cast handles encryption.
     */
    public function setBirthNameAttribute(?string $value): void
    {
        $this->birth_name_enc = $value;
    }

    /**
     * Get decrypted address street (via EncryptedWithDek cast).
     */
    public function getAddressStreetAttribute(): ?string
    {
        return $this->address_street_enc;
    }

    /**
     * Set plaintext address street - Cast handles encryption.
     */
    public function setAddressStreetAttribute(?string $value): void
    {
        $this->address_street_enc = $value;
    }

    /**
     * Get decrypted house number (via EncryptedWithDek cast).
     */
    public function getAddressHouseNumberAttribute(): ?string
    {
        return $this->address_house_number_enc;
    }

    /**
     * Set plaintext house number - Cast handles encryption.
     */
    public function setAddressHouseNumberAttribute(?string $value): void
    {
        $this->address_house_number_enc = $value;
    }

    /**
     * Get decrypted postal code (via EncryptedWithDek cast).
     */
    public function getAddressPostalCodeAttribute(): ?string
    {
        return $this->address_postal_code_enc;
    }

    /**
     * Set plaintext postal code - Cast handles encryption.
     */
    public function setAddressPostalCodeAttribute(?string $value): void
    {
        $this->address_postal_code_enc = $value;
    }

    /**
     * Get decrypted city (via EncryptedWithDek cast).
     */
    public function getAddressCityAttribute(): ?string
    {
        return $this->address_city_enc;
    }

    /**
     * Set plaintext city - Cast handles encryption.
     */
    public function setAddressCityAttribute(?string $value): void
    {
        $this->address_city_enc = $value;
    }

    /**
     * Get decrypted address supplement (via EncryptedWithDek cast).
     */
    public function getAddressSupplementAttribute(): ?string
    {
        return $this->address_supplement_enc;
    }

    /**
     * Set plaintext address supplement - Cast handles encryption.
     */
    public function setAddressSupplementAttribute(?string $value): void
    {
        $this->address_supplement_enc = $value;
    }

    /**
     * Get decrypted ID document number (via EncryptedWithDek cast).
     */
    public function getIdDocumentNumberAttribute(): ?string
    {
        return $this->id_document_number_enc;
    }

    /**
     * Set plaintext ID document number - Cast handles encryption.
     */
    public function setIdDocumentNumberAttribute(?string $value): void
    {
        $this->id_document_number_enc = $value;
    }

    /**
     * Get complete structured address as formatted string.
     *
     * @return string|null Formatted address or null if no address data
     */
    public function getStructuredAddressAttribute(): ?string
    {
        if (! $this->address_street && ! $this->address_city) {
            return null;
        }

        $parts = array_filter([
            trim(($this->address_street ?? '').' '.($this->address_house_number ?? '')),
            $this->address_supplement,
            trim(($this->address_postal_code ?? '').' '.($this->address_city ?? '')),
            $this->address_country ? strtoupper($this->address_country) : null,
        ]);

        return implode(', ', $parts);
    }

    // === STATUS STATE MACHINE ===

    public function isApplicant(): bool
    {
        return $this->status === self::STATUS_APPLICANT;
    }

    public function isPreContract(): bool
    {
        return $this->status === self::STATUS_PRE_CONTRACT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOnLeave(): bool
    {
        return $this->status === self::STATUS_ON_LEAVE;
    }

    public function isTerminated(): bool
    {
        return $this->status === self::STATUS_TERMINATED;
    }

    public function canActivate(): bool
    {
        return $this->status === self::STATUS_PRE_CONTRACT
            && $this->onboarding_completed
            && $this->contract_start_date
            && $this->contract_start_date->isPast();
    }

    public function resolveOnboardingWorkflowStatus(): ?string
    {
        if (is_string($this->onboarding_workflow_status)
            && in_array($this->onboarding_workflow_status, self::VALID_WORKFLOW_STATUSES, true)) {
            return $this->onboarding_workflow_status;
        }

        return self::defaultWorkflowStatusForLifecycleStatus($this->status);
    }

    public function canReceiveOnboardingInvitation(): bool
    {
        return in_array($this->status, self::INVITABLE_STATUSES, true);
    }

    public function canTerminate(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_ON_LEAVE], true);
    }

    public static function defaultWorkflowStatusForLifecycleStatus(string $status): ?string
    {
        return match ($status) {
            self::STATUS_PRE_CONTRACT => self::WORKFLOW_STATUS_INVITED,
            self::STATUS_ACTIVE, self::STATUS_ON_LEAVE, self::STATUS_TERMINATED => self::WORKFLOW_STATUS_ACTIVE,
            default => null,
        };
    }

    // === SCOPES ===

    /**
     * Scope employees with applicant status.
     *
     * @param  Builder<self>  $query
     */
    public function scopeApplicants(Builder $query): void
    {
        $query->where('status', self::STATUS_APPLICANT);
    }

    /**
     * Scope employees with pre_contract status.
     *
     * @param  Builder<self>  $query
     */
    public function scopePreContract(Builder $query): void
    {
        $query->where('status', self::STATUS_PRE_CONTRACT);
    }

    /**
     * Scope employees with active status.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Employees on leave.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnLeave(Builder $query): void
    {
        $query->where('status', self::STATUS_ON_LEAVE);
    }

    /**
     * Scope employees with terminated status.
     *
     * @param  Builder<self>  $query
     */
    public function scopeTerminated(Builder $query): void
    {
        $query->where('status', self::STATUS_TERMINATED);
    }

    /**
     * Scope: Employees within management level range.
     *
     * Filters employees based on minLevel/maxLevel from organizational scope.
     * Implements ADR-009 hierarchical access control.
     *
     * CRITICAL SEMANTICS (null/0):
     * - maxLevel = NULL or 0 → ONLY employees with management_level = 0 (non-management/Guards)
     * - maxLevel = 255 → All management levels (ML1-ML255)
     * - To see ALL employees: Need TWO scopes (one for management_level=0, one for management_level>0)
     *
     * @param  Builder<self>  $query
     * @param  int|null  $minLevel  Minimum management level (inclusive, NULL = no minimum)
     * @param  int|null  $maxLevel  Maximum management level (inclusive, NULL/0 = ONLY non-management!)
     *
     * @see https://github.com/SecPal/api/issues/425
     */
    public function scopeWithinLevelRange(Builder $query, ?int $minLevel, ?int $maxLevel): void
    {
        // CRITICAL: NULL or 0 in max = ONLY non-management employees!
        if ($maxLevel === null || $maxLevel === 0) {
            $query->where('management_level', 0);

            return;
        }

        // Show employees within level range (MANAGEMENT ONLY)
        $query->where('management_level', '>', 0);

        if (! is_null($minLevel)) {
            $query->where('management_level', '>=', $minLevel);
        }

        if (! is_null($maxLevel) && $maxLevel > 0) { // @phpstan-ignore function.impossibleType
            $query->where('management_level', '<=', $maxLevel);
        }
    }

    /**
     * Scope: Employees with user accounts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithUserAccount(Builder $query): void
    {
        $query->whereNotNull('user_id');
    }

    /**
     * Scope: Employees without user accounts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithoutUserAccount(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /**
     * Scope: Employees with incomplete onboarding.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnboardingIncomplete(Builder $query): void
    {
        $query->where('status', self::STATUS_PRE_CONTRACT)
            ->where('onboarding_completed', false);
    }

    /**
     * Scope: Employees with completed onboarding.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnboardingCompleted(Builder $query): void
    {
        $query->where('onboarding_completed', true);
    }

    /**
     * Get default onboarding steps structure.
     *
     * Structure: Each step includes:
     * - id: Unique identifier
     * - name: Human-readable name
     * - completed: Boolean status
     * - completed_at: ISO 8601 timestamp or null
     * - form_submission_id: UUID or null
     *
     * @return array<string, mixed>
     */
    public static function getDefaultOnboardingSteps(): array
    {
        return [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'bank_details',
                    'name' => 'Bankverbindung',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'tax_info',
                    'name' => 'Steuerinformationen',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'qualifications',
                    'name' => 'Qualifikationen & Zertifikate',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'documents',
                    'name' => 'Dokumente hochladen',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'confirmation',
                    'name' => 'Bestätigung & Abschluss',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
            ],
        ];
    }
}
