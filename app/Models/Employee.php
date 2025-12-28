<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Employee model for HR management.
 *
 * Manages employee master data with lifecycle state machine, encryption, and user account integration.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string $employee_number
 * @property string $first_name_enc Encrypted first name
 * @property string|null $first_name_idx Blind index for first name search
 * @property string $last_name_enc Encrypted last name
 * @property string|null $last_name_idx Blind index for last name search
 * @property string|null $date_of_birth_enc Encrypted date of birth (text, not date)
 * @property string|null $date_of_birth_idx Blind index for date of birth
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_encrypted Encrypted address
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
 * @property string|null $organizational_unit_id
 * @property string|null $position Job title/role (e.g., 'Objektleiter Flughafen Berlin')
 * @property int $management_level Management level: 0=non-management, 1=CEO/highest, 2-255=lower levels
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 * @property-read string $first_name Decrypted first name
 * @property-read string $last_name Decrypted last name
 * @property-read string|null $date_of_birth Decrypted date of birth (string, not Carbon)
 * @property-read string|null $address Decrypted address
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
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    /** Status constants */
    public const STATUS_APPLICANT = 'applicant';

    public const STATUS_PRE_CONTRACT = 'pre_contract';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_TERMINATED = 'terminated';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'employee_number',
        'first_name', // plaintext field → triggers mutator → sets first_name_enc → triggers cast
        'first_name_enc',
        'first_name_idx',
        'last_name', // plaintext field → triggers mutator → sets last_name_enc → triggers cast
        'last_name_enc',
        'last_name_idx',
        'date_of_birth', // plaintext field → triggers mutator → sets date_of_birth_enc → triggers cast
        'date_of_birth_enc',
        'date_of_birth_idx',
        'email',
        'phone',
        'address', // plaintext field → triggers mutator → sets address_encrypted → triggers cast
        'address_encrypted',
        'photo_path',
        'tax_id', // plaintext field → triggers mutator → sets tax_id_enc → triggers cast
        'tax_id_enc',
        'social_security_number', // plaintext field → triggers mutator → sets social_security_number_enc → triggers cast
        'social_security_number_enc',
        'status',
        'hire_date',
        'contract_start_date',
        'termination_date',
        'last_working_day',
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
        'date_of_birth_enc',
        'date_of_birth_idx',
        'address_encrypted',
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
            'first_name_enc' => \App\Casts\EncryptedWithDek::class,
            'last_name_enc' => \App\Casts\EncryptedWithDek::class,
            'date_of_birth_enc' => \App\Casts\EncryptedWithDek::class,
            'address_encrypted' => \App\Casts\EncryptedWithDek::class,
            'hourly_rate_enc' => \App\Casts\EncryptedWithDek::class,
            'tax_id_enc' => \App\Casts\EncryptedWithDek::class,
            'social_security_number_enc' => \App\Casts\EncryptedWithDek::class,
            'hire_date' => 'date',
            'contract_start_date' => 'date',
            'termination_date' => 'date',
            'last_working_day' => 'date',
            'sachkunde_expiry' => 'date',
            'work_permit_expiry' => 'date',
            'residence_permit_expiry' => 'date',
            'criminal_record_check_date' => 'date',
            'weekly_hours' => 'decimal:2',
            'monthly_hours' => 'decimal:2',
            'user_account_active' => 'boolean',
            'user_account_activated_at' => 'datetime',
            'user_account_deactivated_at' => 'datetime',
            'onboarding_completed' => 'boolean',
            'onboarding_steps' => 'array',
            'onboarding_started_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /**
     * Configure activity logging.
     *
     * Logs employee changes (Level 2: employee_changes).
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
            ->dontSubmitEmptyLogs()
            ->useLogName('employee_changes');
    }

    /**
     * The "booted" method of the model.
     *
     * Registers event listeners for DSGVO-compliant logging of sensitive personal data.
     * Documents THAT a change occurred without storing the actual values.
     */
    protected static function booted(): void
    {
        static::updating(function (Employee $employee) {
            // Track which sensitive fields changed
            $changedFields = [];

            // Name changes (encrypted fields)
            if ($employee->isDirty('first_name_enc')) {
                $changedFields[] = 'first_name';
            }
            if ($employee->isDirty('last_name_enc')) {
                $changedFields[] = 'last_name';
            }

            // Contact information (personal data)
            if ($employee->isDirty('email')) {
                $changedFields[] = 'email';
            }
            if ($employee->isDirty('phone')) {
                $changedFields[] = 'phone';
            }

            // Highly sensitive encrypted data
            if ($employee->isDirty('date_of_birth_enc')) {
                $changedFields[] = 'date_of_birth';
            }
            if ($employee->isDirty('address_encrypted')) {
                $changedFields[] = 'address';
            }
            if ($employee->isDirty('hourly_rate_enc')) {
                $changedFields[] = 'hourly_rate';
            }
            if ($employee->isDirty('tax_id_enc')) {
                $changedFields[] = 'tax_id';
            }
            if ($employee->isDirty('social_security_number_enc')) {
                $changedFields[] = 'social_security_number';
            }

            // Log if any sensitive fields changed
            if (! empty($changedFields)) {
                activity('employee_changes')
                    ->performedOn($employee)
                    ->causedBy(\Illuminate\Support\Facades\Auth::user())
                    ->withProperties([
                        'changed_fields' => $changedFields,
                        'field_count' => count($changedFields),
                        'note' => 'Sensitive personal data changed - values not logged for GDPR compliance (Art. 5 Abs. 1 lit. c - Data Minimization)',
                    ])
                    ->log('Sensitive data changed (GDPR-compliant: no values stored)');
            }
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
     * Get decrypted address (via EncryptedWithDek cast).
     */
    public function getAddressAttribute(): ?string
    {
        return $this->address_encrypted;
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
     * Set plaintext address - Cast handles encryption.
     */
    public function setAddressAttribute(?string $value): void
    {
        $this->address_encrypted = $value;
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

    public function canTerminate(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_ON_LEAVE], true);
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
