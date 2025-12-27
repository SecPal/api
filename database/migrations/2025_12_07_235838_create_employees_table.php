<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->string('employee_number')->unique();

            // Personal Data (ENCRYPTED via TenantKey)
            $table->text('first_name_enc');
            $table->string('first_name_idx', 64)->nullable(); // Blind index for secure search
            $table->text('last_name_enc');
            $table->string('last_name_idx', 64)->nullable(); // Blind index for secure search
            $table->text('date_of_birth_enc')->nullable();
            $table->string('date_of_birth_idx', 64)->nullable(); // Blind index for age-based queries

            // Email address for employee account creation and authentication.
            // Requirements:
            // - Must be a valid RFC 5322 email address (format validated in application and/or via DB constraint).
            // - Must be unique across all employees (enforced by unique index).
            // - Must be verified before account activation (see onboarding workflow).
            // - Nullable for employees without user accounts; validation/uniqueness applies only when not null.
            $table->string('email')->nullable()->unique(); // Copied to user.email on account creation, used for authentication
            $table->string('phone')->nullable();
            $table->text('address_encrypted')->nullable();
            $table->string('photo_path')->nullable(); // ID badge photo

            // Tax & Social Security (ENCRYPTED - hochsensibel!)
            $table->text('tax_id_enc')->nullable(); // Steueridentifikationsnummer (encrypted)
            $table->text('social_security_number_enc')->nullable(); // Sozialversicherungsnummer (encrypted)

            // Employment Status State Machine
            // State Transitions (see feature-requirements.md):
            // - applicant → pre_contract  (manual: HR creates employee with contract)
            // - pre_contract → active     (automatic: contract_start_date reached + onboarding completed)
            // - pre_contract → terminated (manual: contract cancelled before start)
            // - active → on_leave         (manual: HR marks as on leave)
            // - on_leave → active         (manual: HR marks as back from leave)
            // - active → terminated       (automatic: termination_date reached)
            // - on_leave → terminated     (automatic: termination_date reached while on leave)
            $table->enum('status', [
                'applicant',      // Future: Bewerberverwaltung
                'pre_contract',   // Onboarding phase
                'active',         // Normal operations
                'on_leave',       // Parental leave, sick leave
                'terminated',     // After contract end
            ])->default('applicant');

            // Contract Dates (trigger automatic transitions)
            $table->date('hire_date')->nullable();
            $table->date('contract_start_date')->nullable(); // When access begins
            $table->date('termination_date')->nullable(); // When access ends
            $table->date('last_working_day')->nullable(); // Last day on-site

            // Contract Details
            $table->enum('contract_type', ['full_time', 'part_time', 'minijob', 'freelance']);
            $table->decimal('weekly_hours', 5, 2)->nullable()->comment('Weekly hours for part-time/hourly contracts');
            $table->decimal('monthly_hours', 6, 2)->nullable()->comment('Monthly hours (e.g., 173h/month standard in security)');
            $table->text('hourly_rate_enc')->nullable(); // Salary (encrypted)

            // Health Insurance
            $table->enum('health_insurance_type', ['public', 'private', 'foreign'])->nullable();
            $table->string('health_insurance_provider')->nullable();
            $table->string('health_insurance_number')->nullable();

            // Legal Requirements (BewachV)
            // sachkunde_type can be 'unterrichtung', 'pruefung', or IHK qualification reference
            $table->string('sachkunde_type')->nullable(); // 'unterrichtung', 'pruefung', 'gssk', 'servicekraft', 'fachkraft'
            $table->string('sachkunde_certificate')->nullable();
            $table->date('sachkunde_expiry')->nullable();

            // Work/Residence Permit (Non-EU employees)
            $table->enum('work_permit_type', ['unlimited', 'limited', 'none'])->default('none');
            $table->string('work_permit_number')->nullable();
            $table->date('work_permit_expiry')->nullable(); // NULL = unlimited
            $table->enum('residence_permit_type', ['unlimited', 'limited', 'none'])->default('none');
            $table->string('residence_permit_number')->nullable();
            $table->date('residence_permit_expiry')->nullable(); // NULL = unlimited

            // Criminal Record Check
            $table->enum('criminal_record_status', ['valid', 'expired', 'pending'])->nullable();
            $table->date('criminal_record_check_date')->nullable();

            // System Access (User Account Integration)
            $table->foreignUuid('user_id')->nullable()->constrained('users')->onDelete('restrict');
            $table->boolean('user_account_active')->default(false);
            $table->timestamp('user_account_activated_at')->nullable();
            $table->timestamp('user_account_deactivated_at')->nullable();

            // Pre-Contract Onboarding
            $table->boolean('onboarding_completed')->default(false);
            $table->json('onboarding_steps')->nullable(); // Structure: {"steps": [{"id": "...", "name": "...", "completed": false, "completed_at": null, "form_submission_id": null}]}
            $table->timestamp('onboarding_started_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();

            // Organizational Assignment
            $table->foreignUuid('organizational_unit_id')->nullable()->constrained('organizational_units');
            $table->string('position', 255)->nullable(); // Job title/role (e.g., "Objektleiter Flughafen Berlin")

            // Management Level (Führungsebene)
            // 0 = non-management employee (no management level)
            // 1-255 = management levels (1=highest/CEO, ascending=lower levels)
            // Two separate scope systems: 0/0=non-management, 1-255=management (cannot mix!)
            $table->unsignedTinyInteger('management_level')->default(0)
                ->comment('Management level: 0=non-management, 1=CEO/highest, 2-255=lower levels');

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['status', 'contract_start_date']);
            $table->index('user_account_active');
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('email');
            $table->index('employee_number');
            $table->index('termination_date');
            $table->index(['tenant_id', 'position']);
            $table->index(['tenant_id', 'management_level']); // For hierarchical queries
            // Blind index indexes for encrypted field searches (following pattern from person/secrets tables)
            $table->index(['tenant_id', 'first_name_idx']);
            $table->index(['tenant_id', 'last_name_idx']);
            $table->index(['tenant_id', 'date_of_birth_idx']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
