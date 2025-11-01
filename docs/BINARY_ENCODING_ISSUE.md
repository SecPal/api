<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors

SPDX-License-Identifier: CC0-1.0
-->

# PostgreSQL BYTEA Binary Encoding Issue

## Problem

PersonTest fails with 21 test failures due to binary data encoding mismatch between TenantKey and Person models.

### Error Symptoms

1. **Nonce length error**: `sodium_crypto_secretbox_open(): Argument #2 ($nonce) must be SODIUM_CRYPTO_SECRETBOX_NONCEBYTES bytes long`
   - Expected: 24 bytes
   - Actual: 32 bytes (base64-encoded)

2. **PostgreSQL UTF-8 error**: `invalid byte sequence for encoding "UTF8": 0x...`
   - Occurs when using binary blind indexes in WHERE clauses

## Root Cause Analysis

### Current Implementation (BROKEN)

1. `TenantKey::generateEnvelopeKeys()` returns raw binary (24-byte nonces, 32-byte keys)
2. `TenantKey::create($keys)` triggers SET accessor → base64-encodes binary → stores in BYTEA as ASCII
3. PostgreSQL stores 32-char base64 string as BYTEA (32 bytes)
4. `TenantKey::findOrFail()` → PDO returns BYTEA as stream resource
5. GET accessor: `stream_get_contents()` → 32-byte base64 string → `base64_decode()` → 24-byte binary ✓

**Works in TenantKeyTest** because tests use `TenantKey::create()` immediately followed by unwrap methods on the same model instance (no DB round-trip).

**Fails in PersonTest** because Observer does `TenantKey::findOrFail($tenant_id)` which loads from DB, triggering the GET accessor path.

### Database Evidence

```sql
SELECT encode(idx_nonce, 'hex') as hex_value, length(idx_nonce) as byte_length
FROM tenant_keys LIMIT 1;
```

Result:

- `hex_value`: 51436a31634935456f4e44614e46614e752f2f4a474c68385569634756694d35
- `byte_length`: 32
- Decoded: "QCj1cI5EoNDaNFaNu//JGLh8UicGViM5" (base64 text!)

### Tinker Test (PASSES)

```php
\App\Models\TenantKey::generateKek();
$keys = \App\Models\TenantKey::generateEnvelopeKeys();
$tenant = \App\Models\TenantKey::create($keys);
$loadedTenant = \App\Models\TenantKey::findOrFail($tenant->id);
strlen($loadedTenant->idx_nonce); // 24 ✓
```

But PersonTest FAILS at same point! Why?

## Hypothesis

The issue may be:

1. **Test environment difference**: RefreshDatabase transaction handling
2. **PDO configuration**: Different PDO::PARAM\_\* binding in test vs. tinker
3. **Accessor caching**: Model attribute cache not cleared between operations

## Solutions to Try

### Option 1: Fix Binary Accessors (ATTEMPT MADE, PARTIAL SUCCESS)

```php
protected function idxNonce(): \Illuminate\Database\Eloquent\Casts\Attribute
{
    return \Illuminate\Database\Eloquent\Casts\Attribute::make(
        get: function (mixed $value): string {
            if (is_resource($value)) {
                $value = stream_get_contents($value);
            }
            return base64_decode($value, true);
        },
        set: fn (string $value): string => base64_encode($value),
    );
}
```

**Status**: TenantKeyTest PASSED initially, then FAILED after removing base64 on SET. PersonTest still FAILS.

### Option 2: Use Laravel Binary Cast (RECOMMENDED)

Create custom cast class:

```php
// app/Casts/Binary.php
class Binary implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) return null;
        if (is_resource($value)) {
            return stream_get_contents($value);
        }
        return $value;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value; // Laravel handles BYTEA binding
    }
}
```

Apply to TenantKey:

```php
protected function casts(): array
{
    return [
        'dek_wrapped' => Binary::class,
        'dek_nonce' => Binary::class,
        'idx_wrapped' => Binary::class,
        'idx_nonce' => Binary::class,
        'key_version' => 'integer',
        'created_at' => 'datetime',
    ];
}
```

### Option 3: Fix Person Model Binary Indexes

Person's `email_idx` and `phone_idx` cannot be used directly in WHERE clauses because PostgreSQL rejects binary data as UTF-8.

**Solution**: Use `pg_escape_bytea()` or bind as PDO::PARAM_LOB:

```php
// app/Repositories/PersonRepository.php
public function findByEmail(int $tenantId, string $email): ?Person
{
    $tenantKey = TenantKey::findOrFail($tenantId);
    $normalized = $this->normalizeEmail($email);
    $emailIdx = $tenantKey->generateBlindIndex($normalized);

    return Person::where('tenant_id', $tenantId)
        ->whereRaw('email_idx = ?', [$emailIdx])  // Use raw query with param binding
        ->first();
}
```

Or use base64-encoded indexes (sacrifice storage efficiency):

```php
// Migration change
$table->string('email_idx', 44);  // base64 of 32-byte HMAC
$table->string('phone_idx', 44);

// Observer change
$person->email_idx = base64_encode($tenantKey->generateBlindIndex($normalized));
```

## Action Plan

1. ✅ Commit current WIP state
2. ⏳ Try Option 2 (Binary cast class)
3. ⏳ Fix PersonRepository WHERE clause handling
4. ⏳ Verify TenantKeyTest still passes
5. ⏳ Verify PersonTest passes (all 22 tests)
6. ⏳ Run full test suite
7. ⏳ Pre-push self-review checklist
8. ⏳ Push PR-4

## References

- [Laravel Binary Casting](https://laravel.com/docs/11.x/eloquent-mutators#custom-casts)
- [PostgreSQL BYTEA](https://www.postgresql.org/docs/current/datatype-binary.html)
- [PDO Binary Binding](https://www.php.net/manual/en/pdostatement.bindparam.php) (PDO::PARAM_LOB)
