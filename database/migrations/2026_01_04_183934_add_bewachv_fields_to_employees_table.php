<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add BewachV § 16 mandatory fields for Bewacherregister (BWR) registration.
 *
 * Legal Basis: BewachV § 16 Abs. 2 (Bewachungsverordnung)
 * - Defines all mandatory employee data fields for BWR registration
 * - Includes: Personal identity, birth location, nationalities, address history, qualifications
 *
 * BREAKING CHANGE: Migrates `address_encrypted` to structured address fields.
 * Migration strategy: Existing data preserved in address_encrypted (deprecated field).
 * Future migrations can parse and migrate legacy addresses if needed.
 *
 * Related Issues:
 * - #468: BewachV § 16 - Missing Employee Data Fields
 * - #469: Epic - Employee Onboarding & BewachV Compliance System
 * - #470: Automated Employee Data Deletion (uses retention_period_end)
 * - #471: BWR Registration Workflow (uses bwr_* fields)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // ═══════════════════════════════════════════════════════════════
            // BWR (Bewacherregister) Fields - BewachV § 16 Abs. 3
            // ═══════════════════════════════════════════════════════════════

            // Bewacherregister-Identifikationsnummer (BWR-ID)
            // Format: Exactly 7 digits (0000000-9999999)
            // IMPORTANT: Stored as string to preserve leading zeros!
            $table->string('bwr_id', 7)->nullable()->unique()->after('employee_number');

            // BWR registration status tracking
            $table->enum('bwr_status', [
                'not_registered', // Default: Employee not yet registered
                'pending',        // Export submitted, awaiting approval
                'active',         // Approved and registered
                'suspended',      // Temporarily suspended by authority
                'revoked',        // Registration revoked (disqualified)
            ])->default('not_registered')->after('bwr_id');

            // Timestamps for BWR workflow
            $table->timestamp('bwr_registered_at')->nullable()->after('bwr_status')
                ->comment('When BWR approval was received from authority');
            $table->date('bwr_submission_date')->nullable()->after('bwr_registered_at')
                ->comment('When BWR export was submitted to authority');

            // Internal notes for BWR process (e.g., rejection reasons)
            $table->text('bwr_notes')->nullable()->after('bwr_submission_date');

            // ═══════════════════════════════════════════════════════════════
            // Retention Period Fields - BewachV § 21 Abs. 4 + GDPR Art. 5(1)(e)
            // ═══════════════════════════════════════════════════════════════

            // When employment actually ended (set when status → terminated)
            $table->date('employment_end_date')->nullable()->after('termination_date')
                ->comment('Actual employment end date (for retention calculation)');

            // Auto-calculated: End of calendar year + 3 years (BewachV § 21 Abs. 4)
            // Example: Terminated March 15, 2023 → Retention ends December 31, 2026
            $table->date('retention_period_end')->nullable()->after('employment_end_date')
                ->comment('Legal deadline for data deletion (BewachV §21 + GDPR)');

            // ═══════════════════════════════════════════════════════════════
            // BewachV § 16 Abs. 2 Nr. 2: Gender (Mandatory)
            // ═══════════════════════════════════════════════════════════════

            $table->enum('gender', ['male', 'female', 'diverse'])->nullable()->after('last_name_idx')
                ->comment('BewachV §16 Abs. 2 Nr. 2 - Mandatory for BWR registration');

            // ═══════════════════════════════════════════════════════════════
            // BewachV § 16 Abs. 2 Nr. 1: Names (Birth name, Previous names)
            // ═══════════════════════════════════════════════════════════════

            // Birth name (Geburtsname) if different from current last name
            $table->text('birth_name_enc')->nullable()->after('gender')
                ->comment('Encrypted birth name (if different from last_name)');

            // Previous names (e.g., due to marriage, adoption, legal name change)
            // JSON array: ["Previous Name 1", "Previous Name 2"]
            $table->json('previous_names')->nullable()->after('birth_name_enc')
                ->comment('Array of previous names due to name changes');

            // ═══════════════════════════════════════════════════════════════
            // BewachV § 16 Abs. 2 Nr. 3: Birth Location
            // ═══════════════════════════════════════════════════════════════

            $table->string('birth_city', 100)->nullable()->after('date_of_birth_idx')
                ->comment('City/town of birth (BewachV §16 Abs. 2 Nr. 3)');

            $table->string('birth_country', 2)->nullable()->after('birth_city')
                ->comment('ISO 3166-1 alpha-2 Geburtsland (e.g., DE, PL, TR)');

            // ═══════════════════════════════════════════════════════════════
            // BewachV § 16 Abs. 2 Nr. 4: Nationalities (can have multiple!)
            // ═══════════════════════════════════════════════════════════════

            // JSON array of ISO 3166-1 alpha-2 country codes: ["DE", "PL"]
            // Note: Multiple nationalities possible (dual citizenship)
            $table->json('nationalities')->nullable()->after('birth_country')
                ->comment('Array of ISO 3166-1 alpha-2 codes (e.g., ["DE", "TR"])');

            // ═══════════════════════════════════════════════════════════════
            // BewachV § 16 Abs. 2 Nr. 5: Current Address (STRUCTURED)
            // BREAKING CHANGE: Replaces single address_encrypted field
            // ═══════════════════════════════════════════════════════════════

            $table->text('address_street_enc')->nullable()->after('nationalities')
                ->comment('Encrypted street name (Straße)');

            $table->text('address_house_number_enc')->nullable()->after('address_street_enc')
                ->comment('Encrypted house number (Hausnummer, e.g., 42, 10a)');

            $table->text('address_postal_code_enc')->nullable()->after('address_house_number_enc')
                ->comment('Encrypted postal code (PLZ)');

            $table->text('address_city_enc')->nullable()->after('address_postal_code_enc')
                ->comment('Encrypted city name (Stadt/Ort)');

            $table->text('address_supplement_enc')->nullable()->after('address_city_enc')
                ->comment('Encrypted address supplement (Adresszusatz, e.g., Apartment 4)');

            $table->string('address_country', 2)->nullable()->after('address_supplement_enc')
                ->comment('ISO 3166-1 alpha-2 country code (not encrypted, for filtering)');

            $table->string('address_state', 100)->nullable()->after('address_country')
                ->comment('State/province (Bundesland, not encrypted)');

            // ═══════════════════════════════════════════════════════════════
            // BewachV § 16 Abs. 2 Nr. 6: Address History (Last 5 Years)
            // ═══════════════════════════════════════════════════════════════

            // JSON array of address objects with date ranges
            // Example: [
            //   {
            //     "from": "2020-01-01", "to": "2022-06-30",
            //     "street": "Alte Str.", "house_number": "10",
            //     "postal_code": "10115", "city": "Berlin", "country": "DE"
            //   },
            //   { "from": "2022-07-01", "to": "2025-03-31", ... }
            // ]
            $table->json('address_history')->nullable()->after('address_state')
                ->comment('Array of previous addresses (last 5 years) - BewachV §16 Abs. 2 Nr. 6');

            // ═══════════════════════════════════════════════════════════════
            // BewachV § 16 Abs. 2 Nr. 7: Intended Activities (§ 34a GewO)
            // ═══════════════════════════════════════════════════════════════

            // JSON array of activity codes: ["34a_abs1", "34a_abs2", "patrol"]
            // Maps to § 34a GewO paragraphs:
            // - 34a_abs1: General security duties (all guards)
            // - 34a_abs2: Protection against shoplifting
            // - 34a_abs3: Security at entry areas (bouncers)
            // - 34a_abs4: Security at refugee accommodations
            // - 34a_abs5: City patrol
            $table->json('intended_activities')->nullable()->after('address_history')
                ->comment('Array of intended work activities (BewachV §16 Abs. 2 Nr. 7)');

            // ═══════════════════════════════════════════════════════════════
            // ID Document (for BWR verification + GDPR auto-deletion)
            // ═══════════════════════════════════════════════════════════════

            // Type of ID document
            $table->enum('id_document_type', [
                'id_card',          // Personalausweis
                'passport',         // Reisepass
                'residence_permit', // Aufenthaltstitel
            ])->nullable()->after('intended_activities')
                ->comment('Type of identification document');

            // Document number (encrypted)
            $table->text('id_document_number_enc')->nullable()->after('id_document_type')
                ->comment('Encrypted ID document number (for verification)');

            // Expiry date (important for permit holders)
            $table->date('id_document_expiry')->nullable()->after('id_document_number_enc')
                ->comment('ID document expiry date');

            // Path to scanned ID document copy (GDPR: deleted after BWR approval!)
            $table->string('id_document_copy_path')->nullable()->after('id_document_expiry')
                ->comment('Storage path for ID scan (auto-deleted on BWR approval)');

            // Timestamp when ID scan was auto-deleted (GDPR Art. 30 audit trail)
            $table->timestamp('id_document_copy_deleted_at')->nullable()->after('id_document_copy_path')
                ->comment('When ID scan was deleted (GDPR compliance audit)');

            // ═══════════════════════════════════════════════════════════════
            // BewachV § 16 Abs. 2 Nr. 8: Enhanced Qualification Fields
            // ═══════════════════════════════════════════════════════════════

            // IHK identification number for Sachkunde certificate
            $table->string('sachkunde_ihk_number', 50)->nullable()->after('sachkunde_certificate')
                ->comment('IHK identification number (BewachV §16 Abs. 2 Nr. 8)');

            // Exam date for Sachkunde (required by some authorities)
            $table->date('sachkunde_exam_date')->nullable()->after('sachkunde_ihk_number')
                ->comment('Date of Sachkunde exam');

            // Issue date of Sachkunde certificate
            $table->date('sachkunde_issued_date')->nullable()->after('sachkunde_exam_date')
                ->comment('Date when Sachkunde certificate was issued');

            // ═══════════════════════════════════════════════════════════════
            // Indexes for Performance
            // ═══════════════════════════════════════════════════════════════

            $table->index('bwr_status', 'idx_employees_bwr_status');
            $table->index('retention_period_end', 'idx_employees_retention_period_end');
            $table->index('bwr_registered_at', 'idx_employees_bwr_registered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('idx_employees_bwr_status');
            $table->dropIndex('idx_employees_retention_period_end');
            $table->dropIndex('idx_employees_bwr_registered_at');

            // Drop all new columns in reverse order
            $table->dropColumn([
                // Qualification enhancements
                'sachkunde_issued_date',
                'sachkunde_exam_date',
                'sachkunde_ihk_number',

                // ID Document
                'id_document_copy_deleted_at',
                'id_document_copy_path',
                'id_document_expiry',
                'id_document_number_enc',
                'id_document_type',

                // Intended Activities
                'intended_activities',

                // Address History
                'address_history',

                // Structured Address
                'address_state',
                'address_country',
                'address_supplement_enc',
                'address_city_enc',
                'address_postal_code_enc',
                'address_house_number_enc',
                'address_street_enc',

                // Nationalities
                'nationalities',

                // Birth Location
                'birth_country',
                'birth_city',

                // Names
                'previous_names',
                'birth_name_enc',

                // Gender
                'gender',

                // Retention Period
                'retention_period_end',
                'employment_end_date',

                // BWR Fields
                'bwr_notes',
                'bwr_submission_date',
                'bwr_registered_at',
                'bwr_status',
                'bwr_id',
            ]);
        });
    }
};
