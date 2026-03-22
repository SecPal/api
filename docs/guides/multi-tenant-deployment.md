<!--
SPDX-FileCopyrightText: 2025 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# Multi-Tenant Deployment Guide

Complete guide for deploying SecPal API in **production multi-tenant mode**, where multiple customers (tenants) share the same infrastructure with complete data isolation.

## Overview

SecPal supports **production-ready multi-tenant SaaS deployment** using user-based tenant resolution. Each tenant has:

- ✅ **Complete data isolation** (database-enforced)
- ✅ **Separate encryption keys** (envelope encryption with tenant-specific DEK)
- ✅ **Tenant-scoped RBAC** (roles and permissions per tenant)
- ✅ **Independent configuration** (branding, limits, features)

This guide assumes you've already completed the [Production Deployment Guide](/docs/deployment.md) for basic setup.

---

## Architecture Overview

### Multi-Tenant Data Model

```text
┌──────────────────────────────────────────────────────────┐
│                    SecPal Multi-Tenant                   │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  TenantKey (Encryption Master)                          │
│  ├─ KEK (Key Encryption Key) - shared across tenants    │
│  ├─ DEK (Data Encryption Key) - per tenant              │
│  └─ IDX (Blind Index Key) - per tenant                  │
│                                                          │
│  Tenant 1                   Tenant 2                    │
│  ├─ Users (tenant_id=1)     ├─ Users (tenant_id=2)      │
│  ├─ Sites                   ├─ Sites                    │
│  ├─ Customers               ├─ Customers                │
│  ├─ Employees               ├─ Employees                │
│  └─ Roles & Permissions     └─ Roles & Permissions      │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

### Tenant Resolution Flow

```text
1. User authenticates → Laravel Sanctum issues API token
2. Request with token → auth:sanctum middleware validates
3. InjectTenantId middleware:
   - Extracts user from token
   - Reads user.tenant_id (FK to tenant_keys)
   - Injects tenant_id into request
   - Sets Spatie Permission team ID
4. Controllers query data filtered by tenant_id
5. Policies enforce tenant boundaries
```

**Key Security Properties:**

- ⚠️ Client cannot override tenant_id (removed by middleware)
- ⚠️ User can only access own tenant's data
- ⚠️ Cross-tenant API attacks blocked by 404 responses

---

## Prerequisites

Before deploying multi-tenant:

1. ✅ Complete [Production Deployment Guide](/docs/deployment.md)
2. ✅ PostgreSQL 15+ or 16+ installed and running
3. ✅ PHP 8.4+ with required extensions
4. ✅ Composer 2.x installed
5. ✅ KEK (Key Encryption Key) generated and secured
6. ✅ Application deployed to production server

---

## Deployment Steps

### Step 1: Verify Multi-Tenant Schema

Check that migrations include tenant_id columns:

```bash
# Connect to production database
psql -U secpal_user -d secpal_production

# Verify users table has tenant_id
\d users

# Should show:
# tenant_id | bigint | not null
# Foreign-key constraints:
#   "users_tenant_id_foreign" FOREIGN KEY (tenant_id) REFERENCES tenant_keys(id) ON DELETE CASCADE
```

If migrations not yet run:

```bash
cd /var/www/secpal-api
php artisan migrate
```

**Expected output:**

```text
Migration table created successfully.
Migrating: 2025_12_18_193721_add_tenant_id_to_users_table
Migrated:  2025_12_18_193721_add_tenant_id_to_users_table
Migrating: 2025_12_18_193745_backfill_user_tenant_ids
Migrated:  2025_12_18_193745_backfill_user_tenant_ids
Migrating: 2025_12_18_193808_make_user_tenant_id_not_nullable
Migrated:  2025_12_18_193808_make_user_tenant_id_not_nullable
```

### Step 2: Verify Middleware Configuration

Check that `InjectTenantId` middleware is using user-based resolution:

```bash
# View middleware configuration
cat app/Http/Middleware/InjectTenantId.php | grep -A 10 "user->tenant_id"
```

**Expected output:**

```php
// Resolve tenant_id from authenticated user
$tenantId = $user->tenant_id;
```

**🚨 Security Check:**

```bash
# Verify client-side tenant_id is removed
cat app/Http/Middleware/InjectTenantId.php | grep "request->remove"
```

**Must show:**

```php
$request->request->remove('tenant_id');
$request->query->remove('tenant_id');
```

### Step 3: Create Tenants

Use Laravel Tinker or create a tenant provisioning command:

#### Option A: Using Tinker (Manual)

```bash
php artisan tinker
```

```php
// Generate tenant encryption keys
$keys = \App\Models\TenantKey::generateEnvelopeKeys();

// Create tenant
$tenant = \App\Models\TenantKey::create($keys);

echo "Tenant created: ID = {$tenant->id}\n";
```

#### Option B: Using Artisan Command (Recommended)

Create a custom command for tenant provisioning:

```bash
php artisan make:command CreateTenant
```

Edit `app/Console/Commands/CreateTenant.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\TenantKey;
use Illuminate\Console\Command;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create {name}';
    protected $description = 'Create a new tenant with encryption keys';

    public function handle(): int
    {
        $name = $this->argument('name');

        $this->info("Creating tenant: {$name}");

        // Generate encryption keys
        $keys = TenantKey::generateEnvelopeKeys();

        // Create tenant
        $tenant = TenantKey::create($keys);

        $this->info("✅ Tenant created successfully!");
        $this->table(
            ['Tenant ID', 'Key Version'],
            [[$tenant->id, $tenant->key_version]]
        );

        return Command::SUCCESS;
    }
}
```

**Usage:**

```bash
php artisan tenant:create "Customer Corp"
# Output: ✅ Tenant created successfully!
#         Tenant ID: 1
```

### Step 4: Create Users for Tenants

#### Option A: Registration API (Self-Service)

Users can register themselves:

```bash
curl -X POST https://api.secpal.dev/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@customercorp.com",
    "password": "SecurePassword123!",
    "name": "Admin User",
    "tenant_id": 1
  }'
```

**Response:**

```json
{
  "user": {
    "id": "9d8e7f6a-5b4c-3d2e-1f0e-9d8c7b6a5f4e",
    "email": "admin@customercorp.com",
    "name": "Admin User",
    "tenant_id": 1
  },
  "token": "1|XYZ..."
}
```

#### Option B: Admin User Creation (Tinker)

```bash
php artisan tinker
```

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Create user for tenant 1
$user = User::create([
    'email' => 'admin@tenant1.com',
    'password' => Hash::make('SecurePassword123!'),
    'name' => 'Admin User',
    'tenant_id' => 1, // Assign to tenant 1
]);

// Assign admin role (tenant-scoped)
$user->assignRole('Admin');

echo "User created: {$user->email} (Tenant {$user->tenant_id})\n";
```

### Step 5: Seed Roles and Permissions

Roles and permissions are **tenant-scoped** using Spatie Permission's team feature:

```bash
# Run seeder for initial roles/permissions
php artisan db:seed --class=RolePermissionSeeder
```

**What this does:**

- Creates predefined roles: Admin, Manager, Guard, Client, Works Council
- Assigns permissions to each role
- Roles are **shared across tenants** (same role definitions)
- Role **assignments** are tenant-scoped (User X in Tenant 1 ≠ User X in Tenant 2)

### Step 6: Test Tenant Isolation

Create test data for multiple tenants:

```bash
php artisan tinker
```

```php
// Create 2 tenants
$tenant1 = \App\Models\TenantKey::create(\App\Models\TenantKey::generateEnvelopeKeys());
$tenant2 = \App\Models\TenantKey::create(\App\Models\TenantKey::generateEnvelopeKeys());

// Create users
$user1 = \App\Models\User::create([
    'email' => 'user1@tenant1.com',
    'password' => \Hash::make('password'),
    'tenant_id' => $tenant1->id,
]);

$user2 = \App\Models\User::create([
    'email' => 'user2@tenant2.com',
    'password' => \Hash::make('password'),
    'tenant_id' => $tenant2->id,
]);

// Create sites (example resource)
$orgUnit1 = \App\Models\OrganizationalUnit::factory()->create(['tenant_id' => $tenant1->id]);
$customer1 = \App\Models\Customer::factory()->create(['tenant_id' => $tenant1->id]);

$site1 = \App\Models\Site::create([
    'name' => 'Site 1 (Tenant 1)',
    'tenant_id' => $tenant1->id,
    'customer_id' => $customer1->id,
    'organizational_unit_id' => $orgUnit1->id,
    'type' => 'permanent',
    'address' => ['street' => 'Street 1', 'city' => 'City', 'postal_code' => '12345', 'country' => 'DE'],
]);

$orgUnit2 = \App\Models\OrganizationalUnit::factory()->create(['tenant_id' => $tenant2->id]);
$customer2 = \App\Models\Customer::factory()->create(['tenant_id' => $tenant2->id]);

$site2 = \App\Models\Site::create([
    'name' => 'Site 2 (Tenant 2)',
    'tenant_id' => $tenant2->id,
    'customer_id' => $customer2->id,
    'organizational_unit_id' => $orgUnit2->id,
    'type' => 'permanent',
    'address' => ['street' => 'Street 2', 'city' => 'City', 'postal_code' => '67890', 'country' => 'DE'],
]);

echo "Test data created:\n";
echo "Tenant 1: User {$user1->email}, Site '{$site1->name}'\n";
echo "Tenant 2: User {$user2->email}, Site '{$site2->name}'\n";
```

**Test API calls:**

```bash
# Login as user1
TOKEN1=$(curl -s -X POST https://api.secpal.dev/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user1@tenant1.com","password":"password"}' \
  | jq -r '.token')

# User1 should see only Site 1
curl -s -X GET https://api.secpal.dev/v1/sites \
  -H "Authorization: Bearer $TOKEN1" \
  | jq '.data[].name'

# Expected output: "Site 1 (Tenant 1)"

# Login as user2
TOKEN2=$(curl -s -X POST https://api.secpal.dev/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user2@tenant2.com","password":"password"}' \
  | jq -r '.token')

# User2 should see only Site 2
curl -s -X GET https://api.secpal.dev/v1/sites \
  -H "Authorization: Bearer $TOKEN2" \
  | jq '.data[].name'

# Expected output: "Site 2 (Tenant 2)"
```

**✅ Success Criteria:**

- User1 sees only Tenant1 data
- User2 sees only Tenant2 data
- User1 cannot access Site2 by ID (404 response)

### Step 7: Verify Tenant-Scoped RBAC

Check that role assignments are tenant-scoped:

```bash
php artisan tinker
```

```php
use App\Models\User;

$user1 = User::where('email', 'user1@tenant1.com')->first();
$user2 = User::where('email', 'user2@tenant2.com')->first();

// Assign same role to both users
$user1->assignRole('Manager');
$user2->assignRole('Manager');

// Verify team ID (tenant_id) is set correctly
app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($user1->tenant_id);
echo "User1 has role 'Manager': " . ($user1->hasRole('Manager') ? 'YES' : 'NO') . "\n";

app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($user2->tenant_id);
echo "User2 has role 'Manager': " . ($user2->hasRole('Manager') ? 'YES' : 'NO') . "\n";

// IMPORTANT: Roles are tenant-scoped via Spatie's team feature
// User1's Manager role ≠ User2's Manager role (different tenants)
```

---

## Production Best Practices

### 1. Tenant Provisioning Automation

Create automated tenant provisioning:

```php
// app/Services/TenantProvisioningService.php
class TenantProvisioningService
{
    public function provisionTenant(string $customerName, string $adminEmail): array
    {
        DB::beginTransaction();
        try {
            // 1. Create tenant with encryption keys
            $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());

            // 2. Create admin user
            $admin = User::create([
                'email' => $adminEmail,
                'password' => Hash::make(Str::random(16)), // Send via email
                'name' => 'Admin',
                'tenant_id' => $tenant->id,
            ]);

            // 3. Assign admin role (tenant-scoped)
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            $admin->assignRole('Admin');

            // 4. Create default organizational unit
            $orgUnit = OrganizationalUnit::create([
                'name' => 'Headquarters',
                'type' => 'branch',
                'tenant_id' => $tenant->id,
            ]);

            DB::commit();

            return [
                'tenant_id' => $tenant->id,
                'admin_user_id' => $admin->id,
                'admin_email' => $adminEmail,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
```

### 2. Tenant Usage Monitoring

Track tenant activity and resource usage:

```php
// app/Models/TenantUsage.php
class TenantUsage
{
    public static function getStats(int $tenantId): array
    {
        return [
            'users' => User::where('tenant_id', $tenantId)->count(),
            'sites' => Site::where('tenant_id', $tenantId)->count(),
            'customers' => Customer::where('tenant_id', $tenantId)->count(),
            'employees' => Employee::where('tenant_id', $tenantId)->count(),
        ];
    }
}
```

### 3. Backup Strategy

**Per-Tenant Backups:**

```bash
#!/bin/bash
# backup-tenant.sh

TENANT_ID=$1
BACKUP_DIR="/backups/tenant-${TENANT_ID}"
mkdir -p "$BACKUP_DIR"

# Backup tenant data (PostgreSQL)
pg_dump -U secpal_user \
  --table=users \
  --table=sites \
  --table=customers \
  --where="tenant_id=${TENANT_ID}" \
  secpal_production \
  > "${BACKUP_DIR}/tenant-${TENANT_ID}-$(date +%Y%m%d).sql"

# Backup tenant encryption keys
php artisan tinker --execute="
  \$tenant = \App\Models\TenantKey::find(${TENANT_ID});
  echo json_encode([
    'dek_wrapped' => base64_encode(\$tenant->dek_wrapped),
    'idx_wrapped' => base64_encode(\$tenant->idx_wrapped),
    'key_version' => \$tenant->key_version,
  ]);
" > "${BACKUP_DIR}/tenant-${TENANT_ID}-keys.json"

echo "Backup complete: ${BACKUP_DIR}"
```

### 4. Tenant Deletion (GDPR Compliance)

Safe tenant deletion with cascade:

```php
// app/Services/TenantDeletionService.php
class TenantDeletionService
{
    public function deleteTenant(int $tenantId): void
    {
        DB::beginTransaction();
        try {
            // 1. Export data for audit (GDPR requirement)
            $this->exportTenantData($tenantId);

            // 2. Delete tenant (cascades to all foreign keys)
            $tenant = TenantKey::findOrFail($tenantId);
            $tenant->delete();

            // Cascade deletes:
            // - Users (users.tenant_id FK)
            // - Sites, Customers, Employees, etc.

            DB::commit();

            Log::info("Tenant deleted", ['tenant_id' => $tenantId]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function exportTenantData(int $tenantId): void
    {
        // Export to JSON for audit trail
        $data = [
            'users' => User::where('tenant_id', $tenantId)->get()->toArray(),
            'sites' => Site::where('tenant_id', $tenantId)->get()->toArray(),
            // ... other resources
        ];

        Storage::put(
            "tenant-exports/tenant-{$tenantId}-" . now()->format('Y-m-d') . ".json",
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }
}
```

---

## Monitoring and Logging

### Tenant-Aware Logging

Add tenant_id to all logs:

```php
// app/Http/Middleware/InjectTenantId.php (already implemented)
// After injecting tenant_id:
Log::withContext(['tenant_id' => $tenantId]);
```

**Result:** All subsequent logs include `tenant_id`:

```text
[2025-12-19 10:30:45] local.INFO: Site created {"site_id":123,"tenant_id":1}
```

### Performance Monitoring

Monitor query performance per tenant:

```php
// config/database.php
'connections' => [
    'pgsql' => [
        // ...
        'options' => [
            PDO::ATTR_STATEMENT_CLASS => [TenantAwareStatement::class],
        ],
    ],
],
```

---

## Troubleshooting

### Issue: "User has no assigned tenant"

**Symptom:** 500 error with message "User has no assigned tenant"

**Cause:** User created without tenant_id (should be impossible with NOT NULL constraint)

**Fix:**

```bash
php artisan tinker
```

```php
// Find users without tenant
$usersWithoutTenant = User::whereNull('tenant_id')->get();

if ($usersWithoutTenant->isNotEmpty()) {
    // Assign to first tenant (or create new tenant)
    $firstTenant = TenantKey::first();
    $usersWithoutTenant->each(fn($user) => $user->update(['tenant_id' => $firstTenant->id]));
}
```

### Issue: Cross-Tenant Data Visible

**Symptom:** User can see data from other tenants

**Diagnosis:**

```bash
# Check middleware is applied
php artisan route:list | grep "tenant.inject"

# Expected: All protected routes have tenant.inject middleware
```

**Fix:** Ensure middleware is applied in `routes/api.php`:

```php
Route::middleware(['auth:sanctum', 'tenant.inject'])->group(function () {
    // All tenant-scoped routes
});
```

### Issue: Performance Degradation

**Symptom:** Slow queries with many tenants

**Diagnosis:**

```sql
-- Check if indexes exist
\d sites

-- Should show:
-- Indexes:
--   "sites_tenant_id_index" btree (tenant_id)
```

**Fix:** Add missing indexes:

```php
// Create migration
php artisan make:migration add_tenant_id_indexes

// In migration:
Schema::table('sites', function (Blueprint $table) {
    $table->index('tenant_id');
});
```

---

## Security Checklist

Before going live:

- [ ] ✅ KEK stored outside web root with 0600 permissions
- [ ] ✅ All tenant-scoped routes have `tenant.inject` middleware
- [ ] ✅ Client-side tenant_id spoofing prevented (PR #356)
- [ ] ✅ Tenant isolation tests passing (45+ tests, Issue #361)
- [ ] ✅ Database backups automated (per-tenant)
- [ ] ✅ Monitoring and alerting configured
- [ ] ✅ HTTPS enabled (SSL/TLS certificates)
- [ ] ✅ Rate limiting configured (per tenant)
- [ ] ✅ Audit logging enabled (tenant-aware)

---

## Next Steps

After successful multi-tenant deployment:

1. **Phase 2 Enhancements (Optional):**
   - Subdomain-based tenant resolution (`tenant1.secpal.app`)
   - Tenant management API (CRUD, usage stats)
   - Multi-tenant user access (consultant scenario)

2. **Operational Excellence:**
   - Tenant-aware monitoring dashboards
   - Automated tenant provisioning pipeline
   - Data export/migration tools (GDPR)

3. **Scaling:**
   - Database partitioning by tenant_id
   - Read replicas for high-traffic tenants
   - CDN for tenant-specific assets

---

## Related Documentation

- [Production Deployment Guide](/docs/deployment.md) - Basic setup
- [Tenant Provisioning Guide](/docs/guides/tenant-provisioning.md) - Customer onboarding
- [Migration Guide: Single → Multi-Tenant](/docs/migration-guides/single-to-multi-tenant.md) - Upgrading existing deployments
- [RBAC Architecture](/docs/rbac-architecture.md) - Tenant-scoped permissions
- [ADR-008: User-Based Tenant Resolution](/.github/docs/adr/20251219-user-based-tenant-resolution.md) - Architecture decision

---

**Last Updated:** 2025-12-19
**Epic:** #357 (Production-Ready Multi-Tenant Architecture)
**Status:** ✅ Production Ready
