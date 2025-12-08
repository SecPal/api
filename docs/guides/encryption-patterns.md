<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Encryption Patterns in SecPal

This document defines the encryption patterns used in SecPal for protecting sensitive data at rest.

## Table of Contents

- [Overview](#overview)
- [Encryption Architecture](#encryption-architecture)
- [Pattern 1: Field-Level Encryption with Blind Indexes](#pattern-1-field-level-encryption-with-blind-indexes)
- [Pattern 2: JSON Encryption](#pattern-2-json-encryption)
- [When NOT to Encrypt](#when-not-to-encrypt)
- [Decision Tree](#decision-tree)
- [Implementation Guide](#implementation-guide)
- [Security Considerations](#security-considerations)
- [Key Rotation](#key-rotation)
- [Testing Encrypted Fields](#testing-encrypted-fields)

---

## Overview

SecPal uses **tenant-specific encryption** to protect sensitive employee and customer data. Each tenant has its own encryption keys stored in the `tenant_keys` table.

**Key Principles:**

- ✅ **Encrypt at application layer** (before writing to database)
- ✅ **Tenant isolation** (each tenant has unique DEK)
- ✅ **Key rotation support** (DEK can be rotated without data migration)
- ✅ **Searchability** (via blind indexes using HMAC-SHA256)
- ✅ **Zero trust** (assume database compromise, encryption protects data)

**What we encrypt:**

- Personal identifiable information (PII): names, addresses, dates of birth
- Financial data: salaries, hourly rates, bank details
- Sensitive IDs: tax IDs, social security numbers
- Dynamic form data: emergency contacts, health information

---

## Encryption Architecture

### Key Management

```text
TenantKey (per tenant)
├── KEK (Key Encryption Key) - stored encrypted with APP_KEY
├── DEK (Data Encryption Key) - decrypted in memory, used for field encryption
└── IDX (Index Key) - used for HMAC blind indexes
```

**Encryption Flow:**

```text
Plaintext → Encrypt with DEK → Store as JSON {ciphertext, nonce}
```

**Decryption Flow:**

```text
JSON {ciphertext, nonce} → Decrypt with DEK → Plaintext
```

**Search Flow (Blind Indexes):**

```text
Search term → HMAC-SHA256(IDX, lowercase(term)) → Compare with *_idx column
```

### Storage Format

**Field-Level Encryption:**

```json
{
  "ciphertext": "base64_encoded_ciphertext",
  "nonce": "base64_encoded_nonce"
}
```

**JSON Encryption:**

- Laravel's `encrypted:array` cast handles encryption transparently
- Uses Laravel's application encryption key (`APP_KEY`)
- Suitable for dynamic structures without search requirements

---

## Pattern 1: Field-Level Encryption with Blind Indexes

**Use when:**

- ✅ Known sensitive field (schema-defined column)
- ✅ Need to search/filter by this field
- ✅ Field contains PII or financial data
- ✅ Need audit trail of who accessed decrypted data

**Examples:**

- `Employee.first_name_enc` (with `first_name_idx` for search)
- `Employee.last_name_enc` (with `last_name_idx` for search)
- `Employee.date_of_birth_enc` (with `date_of_birth_idx` for age queries)
- `Employee.hourly_rate_enc` (no search needed, no blind index)
- `Employee.tax_id_enc` (no search needed, no blind index)

### Implementation Steps

#### Step 1: Migration

```php
Schema::table('employees', function (Blueprint $table) {
    // Encrypted field (stores JSON with ciphertext + nonce)
    $table->text('field_name_enc');

    // Blind index for search (HMAC-SHA256, base64 encoded)
    $table->string('field_name_idx', 64)->nullable();

    // Index for fast lookups
    $table->index('field_name_idx');
});
```

#### Step 2: Model Cast

```php
use App\Casts\EncryptedWithDek;

class Employee extends Model
{
    protected $fillable = [
        'field_name_enc',
        'field_name_idx',
    ];

    protected $hidden = [
        'field_name_enc', // Never expose encrypted data
        'field_name_idx', // Never expose blind index
    ];

    protected function casts(): array
    {
        return [
            'field_name_enc' => EncryptedWithDek::class,
        ];
    }

    // Accessor for decrypted value
    public function getFieldNameAttribute(): ?string
    {
        return $this->field_name_enc; // Cast handles decryption
    }
}
```

#### Step 3: Observer for Blind Index

```php
namespace App\Observers;

use App\Models\Employee;
use App\Models\TenantKey;

class EmployeeObserver
{
    public function creating(Employee $employee): void
    {
        $this->updateBlindIndexes($employee);
    }

    public function updating(Employee $employee): void
    {
        if ($employee->isDirty(['field_name_enc'])) {
            $this->updateBlindIndexes($employee);
        }
    }

    private function updateBlindIndexes(Employee $employee): void
    {
        $tenantKey = TenantKey::findOrFail($employee->tenant_id);

        if ($employee->field_name_enc !== null) {
            // Decrypt via accessor (Cast handles it)
            $plaintext = $employee->field_name;

            // Generate HMAC-SHA256 blind index (lowercase for case-insensitive search)
            $rawIdx = $tenantKey->generateBlindIndex(mb_strtolower($plaintext));

            // Store as base64
            $employee->field_name_idx = base64_encode($rawIdx);
        }
    }
}
```

#### Step 4: Searching by Encrypted Field

```php
// Search by first name
$searchTerm = 'John';
$tenantKey = TenantKey::findOrFail($tenantId);
$blindIndex = base64_encode($tenantKey->generateBlindIndex(mb_strtolower($searchTerm)));

$employees = Employee::where('first_name_idx', $blindIndex)->get();
```

### Security Properties

✅ **Encrypted at rest** (database compromise does not reveal plaintext)
✅ **Searchable** (via blind indexes)
✅ **Case-insensitive search** (lowercased before HMAC)
✅ **Tenant-isolated** (each tenant has unique IDX key)
✅ **Key rotation support** (DEK can be rotated, indexes regenerated)
⚠️ **Equality search only** (no LIKE, no range queries)
⚠️ **Rainbow table risk** (mitigated by tenant-specific IDX key + HMAC)

---

## Pattern 2: JSON Encryption

**Use when:**

- ✅ Dynamic/flexible data structure (different forms, varying schemas)
- ✅ No search requirements (only full-document retrieval)
- ✅ Need quick implementation (MVP, prototyping)
- ✅ Data is only accessed by ID (never filtered/searched)

**Examples:**

- `OnboardingFormSubmission.form_data` (employee's onboarding answers)
- Future: `CustomFieldValues.data` (tenant-specific custom fields)

### Implementation Steps

#### Step 1: Migration

```php
Schema::table('onboarding_form_submissions', function (Blueprint $table) {
    // TEXT column for encrypted data (Laravel's encrypted:array cast)
    $table->text('form_data')->nullable();
});
```

#### Step 2: Model Cast

```php
class OnboardingFormSubmission extends Model
{
    protected $fillable = [
        'form_data',
    ];

    protected $hidden = [
        'form_data', // Never expose encrypted data in API responses
    ];

    protected function casts(): array
    {
        return [
            'form_data' => 'encrypted:array', // Laravel's encrypted cast
        ];
    }
}
```

**That's it!** Laravel handles encryption/decryption transparently.

### Usage

```php
// Store encrypted data
$submission = OnboardingFormSubmission::create([
    'form_data' => [
        'emergency_contact_name' => 'Jane Doe',
        'emergency_contact_phone' => '+49 123 456789',
        'bank_iban' => 'DE89 3704 0044 0532 0130 00',
    ],
]);

// Retrieve (automatically decrypted)
$data = $submission->form_data;
echo $data['emergency_contact_name']; // "Jane Doe"
```

### Security Properties

✅ **Encrypted at rest** (database compromise does not reveal plaintext)
✅ **Simple implementation** (no observers, no blind indexes)
✅ **Flexible schema** (JSON can have any structure)
❌ **Not searchable** (cannot filter by nested fields)
❌ **All-or-nothing** (entire JSON encrypted as one blob)
⚠️ **Uses APP_KEY** (shared across all tenants, not tenant-specific)

### When to Upgrade to Field-Level

Consider migrating from JSON encryption to field-level when:

- 🔍 You need to search within the data
- 📊 You need to aggregate/report on specific fields
- 🔒 You need tenant-specific encryption (not APP_KEY)

**Migration Path:**

```php
// Before (JSON encryption)
'form_data' => 'encrypted:array'

// After (Field-level encryption)
$table->text('emergency_contact_name_enc');
$table->string('emergency_contact_name_idx', 64)->nullable();
```

---

## When NOT to Encrypt

**Do NOT encrypt:**

❌ **Non-sensitive reference data**

- Qualification names (system-wide, public information)
- Onboarding form template names (metadata, not PII)
- Status enums (`active`, `terminated`)
- Timestamps (`created_at`, `updated_at`)

❌ **System-generated IDs**

- `employee_number` (public-facing, not sensitive)
- UUIDs (already random, not PII)

❌ **Foreign keys**

- `tenant_id`, `user_id`, `employee_id`
- Needed for joins and relationships

❌ **Publicly known information**

- Company names (tenants)
- Job titles/positions (not PII unless very specific)

**Why?**

- Encryption adds performance overhead
- Encrypted fields cannot use database indexes efficiently
- No security benefit for non-sensitive data

---

## Decision Tree

```text
Is the data sensitive? (PII, financial, health)
│
├─ NO → Don't encrypt
│
└─ YES → Is it a known field with stable schema?
    │
    ├─ YES → Do you need to search/filter by this field?
    │   │
    │   ├─ YES → Pattern 1: Field-Level with Blind Index
    │   │         (first_name_enc, last_name_enc)
    │   │
    │   └─ NO → Pattern 1: Field-Level without Blind Index
    │             (hourly_rate_enc, tax_id_enc)
    │
    └─ NO (dynamic/flexible data) → Pattern 2: JSON Encryption
                                    (form_data, custom_fields)
```

---

## Implementation Guide

### Checklist for New Encrypted Fields

- [ ] **Migration**: Add `field_name_enc` column (text)
- [ ] **Migration**: Add `field_name_idx` column if searchable (varchar 64)
- [ ] **Migration**: Add index on `field_name_idx` if searchable
- [ ] **Model**: Add `field_name_enc` to `$fillable`
- [ ] **Model**: Add `field_name_enc` to `$hidden` (never expose)
- [ ] **Model**: Add `field_name_idx` to `$hidden` if exists
- [ ] **Model**: Add cast `'field_name_enc' => EncryptedWithDek::class`
- [ ] **Model**: Add accessor `getFieldNameAttribute()` if needed
- [ ] **Observer**: Update blind index in `creating` and `updating` if searchable
- [ ] **Observer**: Register in `AppServiceProvider::boot()`
- [ ] **Factory**: Use plaintext values (Cast handles encryption)
- [ ] **Tests**: Test encryption/decryption
- [ ] **Tests**: Test blind index search if applicable
- [ ] **Tests**: Verify encrypted data in database (raw query)

### Example: Adding `middle_name` to Employee

```php
// 1. Migration
Schema::table('employees', function (Blueprint $table) {
    $table->text('middle_name_enc')->nullable()->after('last_name_enc');
    $table->string('middle_name_idx', 64)->nullable()->after('middle_name_enc');
    $table->index('middle_name_idx');
});

// 2. Model
class Employee extends Model
{
    protected $fillable = [
        'middle_name_enc',
        'middle_name_idx',
    ];

    protected $hidden = [
        'middle_name_enc',
        'middle_name_idx',
    ];

    protected function casts(): array
    {
        return [
            'middle_name_enc' => EncryptedWithDek::class,
        ];
    }

    public function getMiddleNameAttribute(): ?string
    {
        return $this->middle_name_enc;
    }
}

// 3. Observer (extend EmployeeObserver)
private function updateBlindIndexes(Employee $employee): void
{
    // ... existing code ...

    if ($employee->middle_name_enc !== null) {
        $plaintext = $employee->middle_name;
        $rawIdx = $tenantKey->generateBlindIndex(mb_strtolower($plaintext));
        $employee->middle_name_idx = base64_encode($rawIdx);
    }
}

// 4. Tests
test('middle_name is encrypted at rest', function () {
    $employee = Employee::factory()->create(['middle_name' => 'Alexander']);

    $raw = DB::table('employees')->where('id', $employee->id)->first();

    expect($raw->middle_name_enc)->toContain('ciphertext')
        ->and($raw->middle_name_idx)->not->toBeNull()
        ->and($employee->middle_name)->toBe('Alexander');
});
```

---

## Security Considerations

### Threat Model

**Protects against:**

- ✅ Database dump theft (encrypted data is useless without KEK/DEK)
- ✅ SQL injection (even if attacker reads data, it's encrypted)
- ✅ Rogue DBA access (DBAs cannot read plaintext)
- ✅ Backup compromise (backups contain encrypted data)

**Does NOT protect against:**

- ❌ Application-level compromise (attacker has access to decrypted data in memory)
- ❌ Key theft (if KEK/DEK are stolen, encryption is broken)
- ❌ Side-channel attacks (timing, cache, spectre/meltdown)

### Best Practices

✅ **Use strong keys**: DEK is 256-bit, generated via `sodium_crypto_secretbox_keygen()`
✅ **Unique nonce per encryption**: Prevents replay attacks
✅ **HMAC for blind indexes**: Prevents rainbow table attacks
✅ **Lowercase before HMAC**: Enables case-insensitive search
✅ **Tenant-specific keys**: Prevents cross-tenant data leakage
✅ **Key rotation**: DEK can be rotated via `php artisan keys:rotate-dek`
✅ **Hide encrypted fields**: Never expose `_enc` or `_idx` columns in API responses

⚠️ **Avoid logging decrypted data**: Log IDs/references, not plaintext
⚠️ **Limit decryption scope**: Only decrypt when needed (don't decrypt all employees at once)
⚠️ **Audit decryption**: Log who accessed sensitive data (future feature)

### Encryption Algorithm

**Symmetric encryption:**

- Algorithm: **XChaCha20-Poly1305** (via libsodium)
- Key size: 256 bits
- Nonce size: 192 bits (XChaCha20 extended nonce)
- Authentication: Poly1305 MAC (AEAD - Authenticated Encryption with Associated Data)

**Blind index:**

- Algorithm: **HMAC-SHA256**
- Key: Tenant-specific IDX key (256 bits)
- Output: 256 bits (32 bytes), base64 encoded to 44 characters

**Why XChaCha20-Poly1305?**

- ✅ Faster than AES on non-AES-NI hardware
- ✅ Constant-time (no timing side-channels)
- ✅ Extended nonce (192 bits) prevents nonce reuse
- ✅ AEAD (authenticated encryption, prevents tampering)
- ✅ Modern standard (used by Google, Cloudflare, WireGuard)

---

## Key Rotation

### When to Rotate Keys

- 🔄 **Annually** (compliance requirement)
- 🚨 **Suspected compromise** (key may have been exposed)
- 👤 **Employee departure** (admin with key access leaves)
- 📜 **Compliance audit** (show key rotation capability)

### How to Rotate DEK

```bash
# Rotate DEK for all tenants
php artisan keys:rotate-dek --all

# Rotate DEK for specific tenant
php artisan keys:rotate-dek --tenant=123
```

**What happens:**

1. Generate new DEK
2. For each encrypted field:
   - Decrypt with old DEK
   - Encrypt with new DEK
   - Update `{ciphertext, nonce}` in database
3. Regenerate all blind indexes with new IDX key
4. Update `tenant_keys.dek_encrypted` with new DEK

**Downtime:** None (done row-by-row with transactions)

---

## Testing Encrypted Fields

### Unit Test Example

```php
use App\Models\Employee;
use App\Models\TenantKey;
use Illuminate\Support\Facades\DB;

test('first_name is encrypted at rest', function () {
    $tenant = TenantKey::factory()->create();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'John',
    ]);

    // Read raw encrypted data from database
    $raw = DB::table('employees')->where('id', $employee->id)->first();

    // Verify encrypted storage
    expect($raw->first_name_enc)
        ->toBeString()
        ->toContain('ciphertext')
        ->toContain('nonce');

    // Verify blind index exists
    expect($raw->first_name_idx)
        ->toBeString()
        ->toHaveLength(44); // base64 encoded 32 bytes

    // Verify accessor decrypts correctly
    expect($employee->first_name)->toBe('John');
});

test('blind index enables case-insensitive search', function () {
    $tenant = TenantKey::factory()->create();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'John']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Jane']);

    // Search by first name (case-insensitive)
    $searchTerm = 'john'; // lowercase
    $blindIndex = base64_encode($tenant->generateBlindIndex(mb_strtolower($searchTerm)));

    $results = Employee::where('first_name_idx', $blindIndex)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->first_name)->toBe('John');
});
```

---

## Related Documentation

- [GUARD_ARCHITECTURE.md](../GUARD_ARCHITECTURE.md) - Authentication guards and API design
- [TenantKey Model](../../app/Models/TenantKey.php) - Key management implementation
- [EncryptedWithDek Cast](../../app/Casts/EncryptedWithDek.php) - Custom cast implementation
- [EmployeeObserver](../../app/Observers/EmployeeObserver.php) - Blind index generation

---

## Questions?

If you're unsure which pattern to use, follow this rule:

### "When in doubt, use Field-Level Encryption with Blind Index."

It's more flexible than JSON encryption and can always be optimized later by removing the blind index if search is not needed.
