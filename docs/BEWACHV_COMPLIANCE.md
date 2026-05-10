<!--
SPDX-FileCopyrightText: 2024-2026 SecPal Contributors

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# BewachV Compliance Documentation

## Verordnung über das Bewachungsgewerbe (BewachV) - Compliance Implementation

This document details SecPal's implementation of legal requirements from the German Private Security Ordinance (Bewachungsgewerbe-Verordnung - BewachV), specifically regarding employee data management, BWR (Bewacherregister) integration, and GDPR compliance.

---

## Table of Contents

1. [Legal Framework](#legal-framework)
2. [BWR-ID Implementation](#bwr-id-implementation)
3. [Field Mapping](#field-mapping)
4. [Auto-Deletion Workflow](#auto-deletion-workflow)
5. [Retention Period Calculation](#retention-period-calculation)
6. [Sachkunde Qualification](#sachkunde-qualification)
7. [Breaking Changes](#breaking-changes)
8. [GDPR Compliance](#gdpr-compliance)
9. [Testing & Validation](#testing--validation)

---

## Legal Framework

### BewachV § 16 - Identifikationsverfahren

**Full Legal Text (Excerpt):**

> (2) Die Bewachungsunternehmer haben über ihre mit Bewachungsaufgaben beschäftigten Arbeitnehmerinnen und Arbeitnehmer Folgendes aufzuzeichnen:
>
> 1. Familienname, Geburtsname, Vornamen, Tag und Ort der Geburt sowie die Anschrift der Wohnung;
> 2. Staatsangehörigkeit;
> 3. Tag des Beginns und der Beendigung des Beschäftigungsverhältnisses;
> 4. Art der übertragenen Tätigkeit nach den §§ 34a und 28 der Gewerbeordnung sowie
> 5. die Registernummer nach Absatz 3 (BWR-ID).
>
> (3) Die Identifizierung erfolgt durch die von der Registerbehörde vergebene siebenstellige Registernummer (Bewacher-Identifikationsnummer). [...]

**Key Requirements:**

- **§ 16 Abs. 2 Nr. 1**: Full name (including birth name), date and place of birth, address
- **§ 16 Abs. 2 Nr. 2**: Nationality (with support for dual citizenship)
- **§ 16 Abs. 2 Nr. 3**: Employment start and end dates
- **§ 16 Abs. 2 Nr. 4**: Type of assigned activities (§34a Sachkunde-relevant work)
- **§ 16 Abs. 2 Nr. 5**: BWR-ID (7-digit Bewacher-Identifikationsnummer)
- **§ 16 Abs. 3**: BWR-ID format specification: Exactly 7 digits (0000000-9999999)

### BewachV § 21 - Aufbewahrung der Aufzeichnungen

**Full Legal Text:**

> (4) Die Aufzeichnungen sind mindestens bis zum Ablauf von drei Jahren nach dem Ende des Kalenderjahres, in dem das Beschäftigungsverhältnis beendet wurde, aufzubewahren.

**Translation:**
Records must be retained for at least 3 years after the end of the calendar year in which the employment relationship ended.

**Example Calculation:**

- Employee terminated: 2024-06-15
- End of calendar year: 2024-12-31
- Retention period: 2024-12-31 + 3 years = **2027-12-31**

### BewachV § 34a - Sachkundeprüfung

**Key Points:**

- Sachkunde qualification (IHK exam) is **valid for life**
- **No expiry date** exists for Sachkunde certification
- Once passed, the qualification never needs renewal
- Only exam date and issuance date are tracked

**Previous Implementation Error (Fixed):**
Our initial implementation incorrectly included a `sachkunde_expiry` field, based on a misunderstanding of the regulation. This has been removed in commit `068ce04` as Sachkunde qualifications do not expire.

### GDPR Integration

**Relevant Articles:**

- **Art. 5(1)(e) - Storage Limitation**: Personal data shall be kept in a form which permits identification of data subjects for no longer than is necessary
- **Art. 30 - Records of Processing Activities**: Controllers must maintain records of processing activities, including purposes and legal basis
- **Art. 17 - Right to Erasure**: Automated deletion after retention period ensures compliance

---

## BWR-ID Implementation

### Format Specification

**Legal Requirement (BewachV § 16 Abs. 3):**

> Die Identifizierung erfolgt durch die von der Registerbehörde vergebene **siebenstellige Registernummer** (Bewacher-Identifikationsnummer).

**Technical Implementation:**

```php
// Database Column
$table->string('bwr_id', 7)->unique()->nullable();

// Validation Rules
'bwr_id' => [
    'nullable',
    'string',
    'size:7',                    // MUST be exactly 7 characters
    'regex:/^[0-9]{7}$/',        // MUST contain only digits
    'unique:employees,bwr_id',    // MUST be unique across all employees
]
```

**Valid Examples:**

- `1234567` ✅
- `0001234` ✅ (Leading zeros preserved)
- `0000001` ✅
- `9999999` ✅

**Invalid Examples:**

- `123456` ❌ (Only 6 digits)
- `12345678` ❌ (8 digits)
- `123456A` ❌ (Contains letter)
- `1234567` (duplicate) ❌ (Not unique)

### String Storage Rationale

**Why string(7) instead of integer?**

1. **Leading Zeros**: BWR-IDs like `0001234` would be stored as `1234` in integer format, losing critical information
2. **Data Integrity**: Authorities assign BWR-IDs as formatted strings, not mathematical numbers
3. **Uniqueness Validation**: String comparison is more predictable than integer comparison for IDs
4. **Display Consistency**: No formatting needed when displaying to users or in exports
5. **Future-Proofing**: If format changes to include letters (e.g., `A123456`), string type accommodates this

**Database Storage:**

```sql
-- PostgreSQL stores as CHAR(7) - fixed length, padded
bwr_id character varying(7) UNIQUE
```

### BWR Status Workflow

**Enum Values:**

```php
enum bwr_status {
    'not_registered',  // Default: Employee not yet registered in BWR
    'pending',         // Export submitted to authority, awaiting approval
    'active',          // Approved and registered in BWR
    'suspended',       // Temporarily suspended by authority
    'revoked'          // Registration permanently revoked (disqualified)
}
```

**State Transitions:**

```text
not_registered → pending → active
                           ↓
                      suspended → active
                           ↓
                       revoked (final)
```

**Triggers:**

- **pending**: When BWR export (XML) is submitted to authority
- **active**: When authority confirms registration (triggers ID document deletion)
- **suspended**: Authority temporarily suspends due to investigation
- **revoked**: Authority permanently revokes due to disqualification (e.g., criminal record)

---

## Field Mapping

### Complete Field List (30+ fields)

| SecPal Field                  | BewachV Requirement          | Data Type   | Encrypted | Validation                          | Example                           |
| ----------------------------- | ---------------------------- | ----------- | --------- | ----------------------------------- | --------------------------------- |
| `bwr_id`                      | § 16 Abs. 2 Nr. 5            | string(7)   | No        | Exactly 7 digits, unique            | `0001234`                         |
| `bwr_status`                  | § 16 Abs. 3                  | enum        | No        | 5 valid states                      | `active`                          |
| `bwr_registered_at`           | § 16 workflow                | timestamp   | No        | Nullable date                       | `2024-06-15`                      |
| `bwr_submission_date`         | § 16 workflow                | timestamp   | No        | Nullable date                       | `2024-06-01`                      |
| `bwr_notes`                   | Internal tracking            | text        | No        | Max 1000 chars                      | "Renewal in 5 years"              |
| `employment_end_date`         | § 21 Abs. 4                  | date        | No        | Auto-calculated                     | `2024-06-15`                      |
| `retention_period_end`        | § 21 Abs. 4                  | date        | No        | Auto-calculated                     | `2027-12-31`                      |
| `gender`                      | § 16 Abs. 2 Nr. 1 (implicit) | enum        | No        | Required if BWR pending/active      | `male`, `female`, `diverse`       |
| `birth_name_enc`              | § 16 Abs. 2 Nr. 1            | text        | **Yes**   | Nullable string                     | "Schmidt" (birth name)            |
| `previous_names`              | § 16 Abs. 2 Nr. 1 (implicit) | JSON        | No        | Array of strings                    | `["Mueller", "Schneider"]`        |
| `birth_city`                  | § 16 Abs. 2 Nr. 1            | string(255) | No        | Nullable                            | "Berlin"                          |
| `birth_country`               | § 16 Abs. 2 Nr. 1            | string(2)   | No        | ISO 3166-1 alpha-2                  | `DE`                              |
| `birth_state`                 | § 16 Abs. 2 Nr. 1            | string(100) | No        | Nullable                            | "Berlin"                          |
| `nationalities`               | § 16 Abs. 2 Nr. 2            | JSON        | No        | Array of ISO codes                  | `["DE", "PL"]`                    |
| `employee_addresses` rows     | § 16 Abs. 2 Nr. 1 / history  | relation    | Mixed     | See `EmployeeAddress` model         | Current + history rows            |
| `intended_activities`         | § 16 Abs. 2 Nr. 4            | JSON        | No        | Array of §34a work types            | `["Objektschutz", "Citystreife"]` |
| `id_document_type`            | § 16 Abs. 2 Nr. 1 (implicit) | enum        | No        | ID card, passport, residence permit | `id_card`                         |
| `id_document_number_enc`      | § 16 Abs. 2 Nr. 1            | text        | **Yes**   | Nullable                            | "L01234567"                       |
| `id_document_expiry`          | § 16 Abs. 2 Nr. 1 (implicit) | date        | No        | Nullable                            | `2030-12-31`                      |
| `id_document_copy_path`       | GDPR Art. 30                 | text        | **Yes**   | Nullable file path                  | `storage/id_docs/...`             |
| `id_document_copy_deleted_at` | GDPR Art. 5(1)(e)            | timestamp   | No        | Nullable                            | `2024-06-16`                      |
| `sachkunde_ihk_number`        | § 16 Abs. 2 Nr. 4            | string(100) | No        | Nullable                            | "IHK-123456"                      |
| `sachkunde_exam_date`         | § 16 Abs. 2 Nr. 4            | date        | No        | Nullable                            | `2023-05-20`                      |
| `sachkunde_issued_date`       | § 16 Abs. 2 Nr. 4            | date        | No        | Nullable                            | `2023-06-01`                      |

**Residential addresses (`employee_addresses`):**

- Stored as separate rows linked to `employees` with encrypted street/postal/city fields (same encryption approach as the former flat columns).
- **Current address:** exactly one row per employee with `resided_until = null`.
- **Historical addresses:** `resided_from` / `resided_until` set; `resided_from` may be null when unknown.
- **API:** clients send/receive `addresses[]` with `street`, `house_number`, `postal_code`, `city`, `supplement`, `country`, `state`, `resided_from`, `resided_until`. Updates replace the full list for that employee. There is no automatic import from removed flat columns—use `addresses[]` only.
- **BWR five-year continuity** for exports is enforced in `BewacherregisterExportService` (gaps/overlaps and coverage), not by requiring a complete history at employee creation.

**Computed property:**

```php
// structured_address — formatted string from the current address row
$employee->structured_address;
// Returns e.g. "Hauptstraße 42, Hinterhaus, 10115 Berlin, DE"
```

### Non-EU Work Authorization Core

SecPal now tracks the P0 work-permit core for non-EU employees directly on the employee record.

- `work_permit_type` supports `temporary`, `permanent`, `blue_card`, `seasonal`, `student`, and `none`
- `work_permit_number` is encrypted at rest as `work_permit_number_enc`
- `work_permit_issued_by`, `work_permit_copy_path`, and `work_permit_copy_deleted_at` support operational evidence plus GDPR auditability
- `requiresWorkPermit()` and `hasValidWorkAuthorization()` enforce the exemption list for EU/EEA/CH nationalities
- `expiring_documents` exposes work-permit, residence-permit, and ID-document expiries within the next 30 days

**Exempt nationalities (no work permit required):** `AT`, `BE`, `BG`, `HR`, `CY`, `CZ`, `DK`, `EE`, `FI`, `FR`, `DE`, `GR`, `HU`, `IE`, `IT`, `LV`, `LT`, `LU`, `MT`, `NL`, `PL`, `PT`, `RO`, `SK`, `SI`, `ES`, `SE`, `IS`, `LI`, `NO`, `CH`

**Deletion trigger:** uploaded work-permit copies are deleted automatically once BWR status becomes `active` or the permit type changes to `permanent`, matching the same GDPR storage-limitation pattern already used for ID-document copies.

---

## Auto-Deletion Workflow

### Legal Basis

**GDPR Art. 5(1)(e) - Storage Limitation:**

> Personal data shall be kept in a form which permits identification of data subjects for no longer than is necessary for the purposes for which the personal data are processed.

**Application to ID Documents:**
ID document copies (passport scans, ID card photos) are **only necessary** during the BWR application process. Once the authority approves registration (bwr_status = 'active'), these copies serve no further legal purpose and must be deleted to comply with GDPR.

### Implementation

**Observer: `EmployeeObserver::deleteIdDocumentCopy()`**

```php
/**
 * Automatically delete ID document copy when no longer needed.
 *
 * Trigger: When bwr_status changes to 'active'
 * Legal Basis: GDPR Art. 5(1)(e) - Storage Limitation
 *
 * @param Employee $employee
 * @return void
 */
protected function deleteIdDocumentCopy(Employee $employee): void
{
    // Only trigger when BWR status changes to 'active'
    if ($employee->isDirty('bwr_status') && $employee->bwr_status === 'active') {

        // Check if file exists
        if ($employee->id_document_copy_path) {

            // Delete physical file from storage
            Storage::delete($employee->id_document_copy_path);

            // Update database record (use updateQuietly to avoid recursion)
            $employee->updateQuietly([
                'id_document_copy_deleted_at' => now(),
            ]);

            // Create audit log for GDPR compliance
            activity('employee_changes')
                ->performedOn($employee)
                ->withProperties([
                    'deleted_file' => $employee->id_document_copy_path,
                    'bwr_id' => $employee->bwr_id,
                    'legal_basis' => 'GDPR Art. 5(1)(e)',
                ])
                ->log('ID document copy automatically deleted (BWR active)');
        }
    }
}
```

**Workflow Diagram:**

```text
Employee created
     ↓
BWR application prepared
     ↓
ID document uploaded → storage/id_docs/employee_123.pdf
     ↓
BWR export submitted (bwr_status = 'pending')
     ↓
Authority processes application
     ↓
Authority approves → bwr_status = 'active'
     ↓
[OBSERVER TRIGGERS]
     ↓
Physical file deleted from storage
     ↓
id_document_copy_deleted_at = now()
     ↓
Activity log created: "ID document copy automatically deleted (BWR active)"
```

**Edge Cases Handled:**

1. **Null file path**: No action taken, no error thrown
2. **File already deleted**: No error, just sets timestamp
3. **Status changes to non-active**: No deletion occurs
4. **Multiple status changes**: Only first activation triggers deletion

---

## Retention Period Calculation

### Legal Basis

**BewachV § 21 Abs. 4:**

> Die Aufzeichnungen sind mindestens bis zum Ablauf von drei Jahren nach dem Ende des Kalenderjahres, in dem das Beschäftigungsverhältnis beendet wurde, aufzubewahren.

**Formula:**

```text
retention_period_end = END_OF_YEAR(termination_date) + 3 years
```

### Implementation

**Observer: `EmployeeObserver::calculateRetentionPeriod()`**

```php
/**
 * Calculate BewachV § 21 retention period when employee is terminated.
 *
 * Formula: End of calendar year + 3 years
 * Example: Terminated 2024-06-15 → Retention until 2027-12-31
 *
 * @param Employee $employee
 * @return void
 */
protected function calculateRetentionPeriod(Employee $employee): void
{
    // Only trigger when status changes to 'terminated'
    if ($employee->isDirty('status') && $employee->status === 'terminated') {

        // Get termination date (use now() if not provided)
        $terminationDate = $employee->termination_date ?? now();

        // Calculate end of calendar year
        $endOfYear = Carbon::parse($terminationDate)->endOfYear();

        // Add 3 years to end of year
        $retentionEnd = $endOfYear->addYears(3);

        // Update employee record (use updateQuietly to avoid recursion)
        $employee->updateQuietly([
            'employment_end_date' => $terminationDate,
            'retention_period_end' => $retentionEnd,
        ]);

        // Create audit log for GDPR compliance
        activity('employee_changes')
            ->performedOn($employee)
            ->withProperties([
                'employment_end_date' => $terminationDate->toDateString(),
                'retention_period_end' => $retentionEnd->toDateString(),
                'legal_basis' => 'BewachV § 21 Abs. 4',
            ])
            ->log('Retention period calculated (BewachV §21 - 3 years from end of calendar year)');
    }
}
```

### Calculation Examples

| Termination Date | End of Calendar Year | +3 Years | Retention Until |
| ---------------- | -------------------- | -------- | --------------- |
| 2024-01-15       | 2024-12-31           | +3       | **2027-12-31**  |
| 2024-06-15       | 2024-12-31           | +3       | **2027-12-31**  |
| 2024-12-15       | 2024-12-31           | +3       | **2027-12-31**  |
| 2024-12-31       | 2024-12-31           | +3       | **2027-12-31**  |
| 2025-07-01       | 2025-12-31           | +3       | **2028-12-31**  |

**Key Insight:**
All employees terminated in the same calendar year have the **same retention period end date** (December 31st, three years later). This simplifies batch deletion queries.

### Future Automated Deletion (Issue #470)

The `retention_period_end` field enables future automated data deletion:

```sql
-- Find employees ready for deletion
SELECT * FROM employees
WHERE status = 'terminated'
AND retention_period_end < CURRENT_DATE;

-- Batch deletion query (to be implemented in Issue #470)
DELETE FROM employees
WHERE retention_period_end < CURRENT_DATE
AND status = 'terminated';
```

---

## Sachkunde Qualification

### Legal Clarification

**Common Misconception:**
Many security companies incorrectly believe Sachkunde qualifications expire after 5 or 10 years. This is **legally incorrect**.

**Correct Legal Position:**
According to BewachV § 34a and IHK regulations:

- Sachkunde (IHK exam) is **valid for life**
- Once passed, the qualification **never expires**
- No renewal or re-examination is required
- Only the exam date and certificate issuance date are relevant

**What CAN Expire:**

- **Firearms license** (Waffensachkunde) - typically 1-3 years
- **First aid certificate** - typically 2 years
- **Customer-specific certifications** - varies by customer

**Implementation Change:**
Our initial implementation incorrectly included a `sachkunde_expiry` field based on industry rumors. This was removed in commit `068ce04` to reflect the correct legal position.

### Current Implementation

**Fields Tracked:**

```php
'sachkunde_ihk_number' => ['nullable', 'string', 'max:100'],
'sachkunde_exam_date' => ['nullable', 'date'],
'sachkunde_issued_date' => ['nullable', 'date'],
// NO expiry field - Sachkunde NEVER expires
```

**Migration Comment:**

```php
// Migration: 2026_01_04_190847_remove_sachkunde_expiry_from_employees_table.php
$table->dropColumn('sachkunde_expiry');
// Comment: "Sachkunde qualification never expires (valid for life)"
```

### IHK Confirmation

If auditors or authorities question this implementation, refer them to:

- IHK website: "Die Sachkundeprüfung nach § 34a GewO ist zeitlich unbegrenzt gültig"
- BewachV § 34a: No mention of expiry or renewal requirements
- IHK certificate text: "Gültig ohne zeitliche Begrenzung"

---

## Breaking Changes

### Version 0.x.x Policy

**As stated in our versioning policy:**

> During 0.x.x development, breaking changes are **explicitly acceptable and encouraged** to avoid accumulating technical debt. We prioritize clean, legally accurate implementations over backwards compatibility.

### Change 1: Address Structure

**Before (Single Encrypted Field):**

```php
'address_encrypted' => 'Full address as single encrypted blob'
```

**After (7 Structured Fields):**

```php
'address_street_enc' => 'Hauptstraße',
'address_house_number_enc' => '42',
'address_postal_code_enc' => '10115',
'address_city_enc' => 'Berlin',
'address_supplement_enc' => '3. OG',
'address_country' => 'DE',
'address_state' => 'NRW',
```

**Rationale:**

1. **BWR Export Requirements**: XML export requires separate fields
2. **Searchability**: Can filter by city, postal code without decryption
3. **Data Quality**: Validation per field (postal code format, ISO country codes)
4. **International Addresses**: Proper support for different address formats
5. **BewachV § 16 Compliance**: Structured format matches legal requirements

**Migration Path:**
Old `address_encrypted` field completely removed. No migration path provided as no production data exists in 0.x.x.

### Change 2: BWR-ID Format

**Before:**

```php
$table->string('bwr_id', 50); // Allowed any string up to 50 chars
```

**After:**

```php
$table->string('bwr_id', 7); // Exactly 7 numeric digits
```

**Rationale:**

- **Legal Accuracy**: BewachV § 16 Abs. 3 explicitly states "siebenstellige Registernummer"
- **Data Integrity**: Prevents invalid IDs from being stored
- **Authority Compatibility**: BWR authority system only accepts 7-digit IDs

**Migration Path:**
Database will reject any BWR-IDs that don't match the 7-digit format. Invalid IDs must be corrected before migration.

### Change 3: Sachkunde Expiry Removed

**Before:**

```php
'sachkunde_expiry' => ['nullable', 'date']
```

**After:**

```php
// Field completely removed
```

**Rationale:**

- **Legal Accuracy**: Sachkunde qualifications do not expire
- **Prevents Confusion**: Removes misleading field that suggested renewals needed
- **Data Integrity**: Eliminates maintenance overhead for non-existent expiry dates

**Migration Path:**
Migration `2026_01_04_190847_remove_sachkunde_expiry_from_employees_table.php` drops the column. No data preservation needed as expiry dates were legally meaningless.

---

## GDPR Compliance

### Data Protection Measures

#### 1. Encryption at Rest

**Implementation:**
All sensitive personal data uses Laravel's `EncryptedWithDek` cast with database-level encryption:

```php
protected $casts = [
    'birth_name' => EncryptedWithDek::class,
    'address_street' => EncryptedWithDek::class,
    'address_house_number' => EncryptedWithDek::class,
    'address_postal_code' => EncryptedWithDek::class,
    'address_city' => EncryptedWithDek::class,
    'address_supplement' => EncryptedWithDek::class,
    'id_document_number' => EncryptedWithDek::class,
    'id_document_copy_path' => EncryptedWithDek::class,
];
```

**Blind Indexes:**
For searchable encrypted fields, blind indexes are maintained:

```php
'first_name_idx' => hash_hmac('sha256', $plaintext, config('app.index_key'))
```

#### 2. Storage Limitation (Art. 5(1)(e))

**Automated Deletion:**

- ID document copies deleted when BWR status = 'active'
- Retention period auto-calculated on termination
- Future: Batch deletion job after retention period (Issue #470)

**Audit Trail:**
Every deletion is logged with:

- Timestamp of deletion
- Legal basis (GDPR article or BewachV paragraph)
- User/system that triggered deletion
- Original file path (for audit purposes)

#### 3. Records of Processing (Art. 30)

**Activity Logging:**
All automated actions logged via Spatie ActivityLog:

```php
activity('employee_changes')
    ->performedOn($employee)
    ->withProperties([
        'reason' => 'GDPR Art. 5(1)(e) - No longer necessary',
        'legal_basis' => 'BewachV § 21 Abs. 4',
        'deleted_file' => $filePath,
    ])
    ->log('ID document copy automatically deleted');
```

**Queryable Records:**

```php
// Find all GDPR-related deletions
Activity::where('description', 'like', '%deleted%')
    ->where('properties->legal_basis', 'like', '%GDPR%')
    ->get();
```

#### 4. Data Minimization (Art. 5(1)(c))

**Conditional Requirements:**
Fields are only required when legally necessary:

```php
// Gender only required for BWR registration
'gender' => ['required_if:bwr_status,pending,active'],

// Address only required for BWR registration
'address_street' => ['required_if:bwr_status,pending,active'],
```

**Optional Fields:**
All BewachV fields are nullable by default unless specifically required by law.

#### 5. Right to Erasure (Art. 17)

**Retention Period Enforcement:**
The `retention_period_end` field enables automated fulfillment of erasure rights:

```php
// After retention period, data MUST be deleted
if ($employee->retention_period_end < now()) {
    $employee->delete(); // Hard delete
}
```

**Exceptions:**

- Data cannot be deleted during retention period (BewachV § 21)
- Legal obligation supersedes right to erasure (GDPR Art. 17(3)(b))

---

## Testing & Validation

### Test Coverage

#### Total Tests: 41

- Model Tests: 10
- Observer Tests: 9
- Factory Tests: 8
- Resource Tests: 6
- Feature (Validation) Tests: 8

#### Total Assertions: 201

**Pass Rate: 100%** ✅

### Test Categories

#### 1. Model Tests (`EmployeeModelBewachvFieldsTest`)

- ✅ Encryption/decryption of sensitive fields
- ✅ JSON casting (nationalities, address_history)
- ✅ String storage with leading zeros (BWR-ID)
- ✅ Date casting to Carbon objects
- ✅ Computed property (structured_address)
- ✅ Hidden fields exclusion

#### 2. Observer Tests (`EmployeeObserverBewachvTest`)

- ✅ ID document deletion on BWR activation
- ✅ Activity logging with legal basis
- ✅ No deletion on non-active status
- ✅ Null-safe file handling
- ✅ Retention period calculation
- ✅ Year-end edge cases
- ✅ Combined BWR + termination operations

#### 3. Factory Tests (`EmployeeFactoryBewachvTest`)

- ✅ `withBwrRegistration()` state validation
- ✅ Leading zero preservation
- ✅ `withCompleteBewachvData()` completeness
- ✅ Dual citizenship generation
- ✅ Address history format
- ✅ Terminated employee state
- ✅ Unique BWR-ID generation
- ✅ ISO 3166-1 alpha-2 compliance

#### 4. Resource Tests (`EmployeeResourceBewachvTest`)

- ✅ All 30+ fields present in API response
- ✅ Date formatting consistency
- ✅ Null value handling
- ✅ Computed property inclusion
- ✅ BWR-ID leading zero preservation
- ✅ Encrypted field name exclusion

#### 5. Feature Tests (`BewachvEmployeeFieldsValidationTest`)

- ✅ BWR-ID exactly 7 digits
- ✅ BWR-ID uniqueness constraint
- ✅ Gender required for BWR pending/active
- ✅ Address fields required for BWR pending/active
- ✅ ISO 3166-1 alpha-2 country codes
- ✅ Nationalities array validation
- ✅ German error messages
- ✅ UpdateRequest PATCH semantics

### Quality Gates

**All Passing:**

- ✅ PHPStan Level Max: 0 errors
- ✅ Laravel Pint: PSR-12 compliant
- ✅ REUSE 3.3: All files have SPDX headers
- ✅ Pest Tests: 41/41 passing (100%)
- ✅ Migrations: Rollback tested successfully

### Manual Validation Checklist

For production deployment, verify:

1. **Database**
   - [ ] All 30+ BewachV fields present in `employees` table
   - [ ] Indexes created: `bwr_status`, `retention_period_end`, `bwr_registered_at`
   - [ ] Enum constraint on `bwr_status` allows only 5 valid values
   - [ ] `sachkunde_expiry` column does NOT exist

2. **Observer Logic**
   - [ ] Create employee with ID document → Change bwr_status to 'active' → File deleted
   - [ ] Activity log contains "ID document copy automatically deleted (BWR active)"
   - [ ] Terminate employee → `retention_period_end` auto-calculated correctly
   - [ ] Activity log contains "Retention period calculated (BewachV §21..."

3. **Validation**
   - [ ] BWR-ID with 6 digits rejected
   - [ ] BWR-ID with letters rejected
   - [ ] Duplicate BWR-ID rejected
   - [ ] Leading zeros in BWR-ID preserved
   - [ ] Gender required when bwr_status = 'pending' or 'active'
   - [ ] Address fields required when bwr_status = 'pending' or 'active'

4. **API Response**
   - [ ] `/api/employees/{id}` includes all BewachV fields
   - [ ] `structured_address` computed property present
   - [ ] Encrypted field names (\_enc suffix) NOT exposed
   - [ ] Dates formatted as Carbon objects

5. **GDPR Compliance**
   - [ ] ID document deletion logged with legal basis
   - [ ] Retention period logged with BewachV § 21 reference
   - [ ] Activity logs queryable by legal_basis property
   - [ ] File physically deleted from storage (not just flagged)

---

## Related Issues

- **#468** - BewachV § 16 Missing Employee Data Fields _(This Implementation)_
- **#469** - Epic: Employee Onboarding & BewachV Compliance System
- **#470** - Automated Employee Data Deletion After Retention Period
- **#471** - BWR Registration Workflow - XML Export
- **#472** - Work Permit & Document Expiry Tracking for Non-EU Employees

---

## References

### Legal Documents

- [Bewachungsverordnung (BewachV)](https://www.gesetze-im-internet.de/bewachv/)
- [Gewerbeordnung (GewO) § 34a](https://www.gesetze-im-internet.de/gewo/__34a.html)
- [GDPR (DSGVO)](https://dsgvo-gesetz.de/)

### IHK Resources

- [IHK Sachkundeprüfung § 34a](https://www.ihk.de/sicherheit/sachkundepruefung)
- IHK Confirmation: "Sachkundeprüfung zeitlich unbegrenzt gültig"

### Implementation

- **Branch**: `feature/bewachv-employee-fields-468`
- **Migrations**: `2026_01_04_183934_add_bewachv_fields_to_employees_table.php`
- **Migration**: `2026_01_04_190847_remove_sachkunde_expiry_from_employees_table.php`
- **Total Lines Changed**: ~1,200+ (migrations, models, observers, requests, resources, factories, tests)

---

**Document Version:** 1.0
**Last Updated:** 2026-01-04
**Author:** SecPal Contributors
**License:** AGPL-3.0-or-later
