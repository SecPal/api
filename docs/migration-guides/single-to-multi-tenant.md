<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Migration Guide: Single-Tenant → Multi-Tenant

**⚠️ Note:** This guide is intended for **future production migrations** only. SecPal is currently in pre-production development.

**For current development/staging environments:** Use `php artisan migrate:fresh --seed`, which automatically sets up multi-tenant mode via the `DatabaseSeeder`. No manual migration needed.

---

Guide for upgrading existing SecPal **production deployments** from single-tenant development mode to production-ready multi-tenant architecture.

---

## Overview

This migration enables SecPal to support **multiple customers (tenants)** on the same infrastructure with complete data isolation.

### What Changes

**Before (Single-Tenant Development Mode):**

- ❌ Hardcoded tenant resolution: `TenantKey::oldest('id')`
- ❌ All users share the same tenant
- ❌ Cannot deploy to multiple customers
- ❌ No user-tenant relationship in database

**After (Multi-Tenant Production Mode):**

- ✅ User-based tenant resolution: `$user->tenant_id`
- ✅ Each user belongs to exactly one tenant
- ✅ Complete tenant isolation (database-enforced)
- ✅ Production-ready for multiple customers

---

## Breaking Changes

⚠️ **This migration includes BREAKING CHANGES:**

1. **Database Schema:** Adds `tenant_id` column to `users` table (NOT NULL)
2. **Middleware:** `InjectTenantId` now requires authenticated users
3. **Registration:** Must assign `tenant_id` during user creation
4. **Seeders:** Must explicitly set `tenant_id` for all users

**Impact:** All deployments must run migrations. Existing single-tenant deployments are **backward-compatible** (all users assigned to first tenant).

---

## Prerequisites

Before starting migration:

1. ✅ **Backup database** (full backup + tenant encryption keys)
2. ✅ **Test in staging** environment first
3. ✅ **Verify all tests pass** before migration
4. ✅ **Schedule maintenance window** (5-10 minutes downtime)
5. ✅ **Notify users** of planned maintenance

---

## Migration Steps

### Step 1: Backup Current State

#### 1.1. Backup Database

```bash
# PostgreSQL backup
pg_dump -U secpal_user secpal_production > /backups/pre-multi-tenant-$(date +%Y%m%d).sql

# Verify backup
ls -lh /backups/pre-multi-tenant-*.sql
```

#### 1.2. Backup Encryption Keys

```bash
# Backup KEK
cp storage/keys/kek.key /backups/kek-$(date +%Y%m%d).key
chmod 0400 /backups/kek-*.key

# Backup tenant encryption keys
php artisan tinker --execute="
  \$tenants = \App\Models\TenantKey::all();
  foreach (\$tenants as \$tenant) {
    echo json_encode([
      'id' => \$tenant->id,
      'dek_wrapped' => base64_encode(\$tenant->dek_wrapped),
      'idx_wrapped' => base64_encode(\$tenant->idx_wrapped),
      'key_version' => \$tenant->key_version,
    ]) . PHP_EOL;
  }
" > /backups/tenant-keys-$(date +%Y%m%d).json
```

#### 1.3. Verify Current State

```bash
# Count existing users
php artisan tinker --execute="
  echo 'Total users: ' . \App\Models\User::count() . PHP_EOL;
  echo 'Total tenants: ' . \App\Models\TenantKey::count() . PHP_EOL;
"

# Expected output (single-tenant):
# Total users: 5
# Total tenants: 1
```

---

### Step 2: Deploy Updated Code

#### 2.1. Pull Latest Code

```bash
cd /var/www/secpal-api

# Backup current code
tar -czf /backups/secpal-api-$(date +%Y%m%d).tar.gz .

# Pull multi-tenant changes
git fetch origin
git checkout main
git pull origin main

# Verify multi-tenant implementation
grep -n "user->tenant_id" app/Http/Middleware/InjectTenantId.php
```

**Expected output:**

```php
// Line 52: Resolve tenant_id from authenticated user
$tenantId = $user->tenant_id;
```

#### 2.2. Install Dependencies

```bash
# Update Composer dependencies (if needed)
composer install --no-dev --optimize-autoloader

# Clear caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

### Step 3: Run Migrations (3-Step Process)

⚠️ **CRITICAL:** Migrations must run in sequence. Do NOT skip steps.

#### 3.1. Add Nullable `tenant_id` Column

```bash
php artisan migrate --path=database/migrations/2025_12_18_193721_add_tenant_id_to_users_table.php
```

**Expected output:**

```text
Migrating: 2025_12_18_193721_add_tenant_id_to_users_table
Migrated:  2025_12_18_193721_add_tenant_id_to_users_table (150ms)
```

**Verify:**

```bash
php artisan tinker --execute="
  \$user = \App\Models\User::first();
  echo 'User tenant_id: ' . (\$user->tenant_id ?? 'NULL') . PHP_EOL;
"

# Expected output: User tenant_id: NULL
```

#### 3.2. Backfill User Tenant IDs

```bash
php artisan migrate --path=database/migrations/2025_12_18_193745_backfill_user_tenant_ids.php
```

**Expected output:**

```text
Migrating: 2025_12_18_193745_backfill_user_tenant_ids
Migrated:  2025_12_18_193745_backfill_user_tenant_ids (80ms)
```

**Verify:**

```bash
php artisan tinker --execute="
  \$firstTenantId = \App\Models\TenantKey::oldest('id')->value('id');
  \$usersWithTenant = \App\Models\User::where('tenant_id', \$firstTenantId)->count();
  \$usersWithoutTenant = \App\Models\User::whereNull('tenant_id')->count();

  echo 'Users assigned to tenant: ' . \$usersWithTenant . PHP_EOL;
  echo 'Users without tenant: ' . \$usersWithoutTenant . PHP_EOL;
"

# Expected output:
# Users assigned to tenant: 5
# Users without tenant: 0
```

#### 3.3. Make `tenant_id` NOT NULL

```bash
php artisan migrate --path=database/migrations/2025_12_18_193808_make_user_tenant_id_not_nullable.php
```

**Expected output:**

```text
Migrating: 2025_12_18_193808_make_user_tenant_id_not_nullable
Migrated:  2025_12_18_193808_make_user_tenant_id_not_nullable (100ms)
```

**Verify:**

```bash
# Check database schema
psql -U secpal_user -d secpal_production -c "\d users" | grep tenant_id

# Expected output:
# tenant_id | bigint | not null
```

---

### Step 4: Verify Migration Success

#### 4.1. Test User Authentication

```bash
# Test login
TOKEN=$(curl -s -X POST https://api.secpal.dev/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' \
  | jq -r '.token')

echo "Token: $TOKEN"

# Test API request (should use user's tenant_id)
curl -s -X GET https://api.secpal.dev/v1/me \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.tenant_id'

# Expected output: 1
```

#### 4.2. Test Tenant Isolation (Create Second Tenant)

```bash
php artisan tinker
```

```php
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Create second tenant
$tenant2 = TenantKey::create(TenantKey::generateEnvelopeKeys());
echo "Tenant 2 created: ID = {$tenant2->id}\n";

// Create user in second tenant
$user2 = User::create([
    'email' => 'user2@tenant2.com',
    'password' => Hash::make('password'),
    'name' => 'User 2',
    'tenant_id' => $tenant2->id,
]);
echo "User 2 created: {$user2->email} (Tenant {$user2->tenant_id})\n";

// Verify user1 cannot see user2's data
// (Tested via API calls with different tokens)
```

**Test via API:**

```bash
# Login as user2
TOKEN2=$(curl -s -X POST https://api.secpal.dev/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email":"user2@tenant2.com","password":"password"}' \
  | jq -r '.token')

# User2 should have different tenant_id
curl -s -X GET https://api.secpal.dev/v1/me \
  -H "Authorization: Bearer $TOKEN2" \
  | jq '.tenant_id'

# Expected output: 2
```

#### 4.3. Verify Existing Data Access

```bash
# User1 (tenant 1) should still see all their data
curl -s -X GET https://api.secpal.dev/v1/sites \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data | length'

# Expected output: 5 (or whatever the original count was)
```

---

### Step 5: Update Application Configuration (Optional)

#### 5.1. Enable Registration with Tenant Assignment

If allowing self-service registration:

```php
// app/Http/Controllers/Auth/RegisterController.php
public function register(Request $request)
{
    $validated = $request->validate([
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8',
        'tenant_id' => 'nullable|exists:tenant_keys,id', // Optional
    ]);

    // Default to first tenant if not specified
    $tenantId = $validated['tenant_id'] ?? TenantKey::oldest('id')->value('id');

    $user = User::create([
        'email' => $validated['email'],
        'password' => Hash::make($validated['password']),
        'tenant_id' => $tenantId,
    ]);

    return response()->json(['user' => $user], 201);
}
```

#### 5.2. Update Seeders (Development Only)

```php
// database/seeders/DatabaseSeeder.php
public function run(): void
{
    // Create tenants first
    $tenant1 = TenantKey::factory()->create();
    $tenant2 = TenantKey::factory()->create();

    // Create users for tenant 1
    User::factory()->count(5)->create(['tenant_id' => $tenant1->id]);

    // Create users for tenant 2
    User::factory()->count(3)->create(['tenant_id' => $tenant2->id]);
}
```

---

### Step 6: Rollback Plan (If Needed)

⚠️ **Use only if migration fails critically**

#### 6.1. Restore Database

```bash
# Stop application
sudo systemctl stop php8.4-fpm nginx

# Restore database from backup
psql -U secpal_user -d secpal_production < /backups/pre-multi-tenant-20251219.sql

# Verify restoration
php artisan tinker --execute="
  echo 'Total users: ' . \App\Models\User::count() . PHP_EOL;
"
```

#### 6.2. Restore Code

```bash
cd /var/www/secpal-api

# Restore previous code version
tar -xzf /backups/secpal-api-20251219.tar.gz

# Clear caches
php artisan config:clear
php artisan route:clear

# Restart services
sudo systemctl start php8.4-fpm nginx
```

#### 6.3. Verify Rollback

```bash
# Test login
curl -X POST https://api.secpal.dev/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# Should receive token (old system working)
```

---

## Post-Migration Checklist

After successful migration:

- [ ] ✅ All users have `tenant_id` assigned (no NULL values)
- [ ] ✅ User authentication works (login successful)
- [ ] ✅ Existing data accessible (sites, customers, etc.)
- [ ] ✅ Tenant isolation verified (user1 cannot see user2's data)
- [ ] ✅ All tests passing (`php artisan test`)
- [ ] ✅ Backup completed (database + encryption keys)
- [ ] ✅ Monitoring and alerting configured
- [ ] ✅ Documentation updated (internal wiki, if applicable)
- [ ] ✅ Users notified (maintenance complete)

---

## Monitoring Post-Migration

### Check for Errors

```bash
# Tail Laravel logs
tail -f storage/logs/laravel.log

# Look for:
# - "User has no assigned tenant" errors (should not occur)
# - Authentication failures
# - 404 errors (cross-tenant access attempts)
```

### Performance Metrics

```bash
# Query performance (should be similar to pre-migration)
php artisan tinker --execute="
  \$start = microtime(true);
  \App\Models\Site::paginate(15);
  \$duration = (microtime(true) - \$start) * 1000;
  echo 'Query duration: ' . round(\$duration, 2) . ' ms' . PHP_EOL;
"

# Expected: <200ms (similar to before)
```

---

## Troubleshooting

### Issue: "User has no assigned tenant"

**Symptom:** 500 error when accessing API

**Cause:** Migration step 2 (backfill) did not run successfully

**Fix:**

```bash
# Check users without tenant_id
php artisan tinker --execute="
  \$users = \App\Models\User::whereNull('tenant_id')->get();
  echo 'Users without tenant: ' . \$users->count() . PHP_EOL;
  foreach (\$users as \$user) {
    echo '- ' . \$user->email . PHP_EOL;
  }
"

# Assign to first tenant
php artisan tinker --execute="
  \$firstTenantId = \App\Models\TenantKey::oldest('id')->value('id');
  \App\Models\User::whereNull('tenant_id')->update(['tenant_id' => \$firstTenantId]);
  echo 'Users updated.' . PHP_EOL;
"
```

### Issue: "Cannot create user without tenant_id"

**Symptom:** User registration fails

**Cause:** Registration logic not updated to assign `tenant_id`

**Fix:** Update registration controller (see Step 5.1)

### Issue: Tests Failing

**Symptom:** Tests fail with "tenant_id" errors

**Cause:** Test fixtures not updated

**Fix:**

```php
// tests/TestCase.php
protected function setUp(): void
{
    parent::setUp();

    // Create tenant for tests
    $this->tenant = TenantKey::factory()->create();

    // Create authenticated user with tenant
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
}
```

---

## FAQ

### Q: Can I skip the migration and stay single-tenant?

**A:** No. Future updates assume multi-tenant architecture. Single-tenant deployments must migrate but will function identically (all users in one tenant).

### Q: Will this migration cause downtime?

**A:** Yes, 5-10 minutes of downtime during migration. Plan maintenance window accordingly.

### Q: Can I migrate one tenant at a time?

**A:** No. All users migrate together. But existing users stay in the same tenant (first tenant), ensuring no disruption.

### Q: What happens to existing API tokens?

**A:** Tokens remain valid. User's `tenant_id` is resolved from the authenticated user, not the token.

### Q: Can users switch tenants later?

**A:** Not in v1.0. Users belong to exactly one tenant. Future enhancement (Phase 2) may support multi-tenant access.

### Q: How do I add more tenants after migration?

**A:** Use the provisioning command:

```bash
php artisan tenant:provision "New Customer" "admin@newcustomer.com"
```

See [Tenant Provisioning Guide](/docs/guides/tenant-provisioning.md) for details.

---

## Success Criteria

Migration is successful when:

1. ✅ **Zero Data Loss:** All users, sites, customers preserved
2. ✅ **Authentication Works:** Existing users can login
3. ✅ **Tenant Isolation:** User1 cannot see User2's data (different tenants)
4. ✅ **Performance Maintained:** Query times similar to pre-migration
5. ✅ **Tests Passing:** All 1452 tests pass (including 45 new tenant isolation tests)
6. ✅ **No Errors:** No "User has no assigned tenant" errors in logs
7. ✅ **Backward Compatible:** Single-tenant deployments function identically

---

## Related Documentation

- [Multi-Tenant Deployment Guide](/docs/guides/multi-tenant-deployment.md) - Production setup
- [Tenant Provisioning Guide](/docs/guides/tenant-provisioning.md) - Adding new tenants
- [RBAC Architecture](/docs/rbac-architecture.md) - Tenant-scoped permissions
- [ADR-008: User-Based Tenant Resolution](https://github.com/SecPal/.github/blob/main/docs/adr/20251219-user-based-tenant-resolution.md) - Architecture decision
- [Epic #357](https://github.com/SecPal/api/issues/357) - Implementation tracking

---

**Last Updated:** 2025-12-19
**Epic:** #357 (Production-Ready Multi-Tenant Architecture)
**Status:** ✅ Production Ready
**Migration Version:** 1.0 (User-Based Tenant Resolution)
