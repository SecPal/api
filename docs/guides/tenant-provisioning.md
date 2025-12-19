<!--
SPDX-FileCopyrightText: 2025 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# Tenant Provisioning Guide

Step-by-step guide for provisioning new tenants (customers) in SecPal's multi-tenant environment.

## Overview

**Tenant provisioning** is the process of onboarding a new customer to SecPal. Each tenant gets:

1. ✅ **Unique encryption keys** (DEK + IDX for envelope encryption)
2. ✅ **Admin user account** with full permissions
3. ✅ **Default organizational structure** (HQ organizational unit)
4. ✅ **Predefined roles** (Admin, Manager, Guard, Client, Works Council)
5. ✅ **Isolated data space** (cannot access other tenants)

---

## Automatic Tenant Creation (Development)

**For development/staging environments**, the `DatabaseSeeder` **automatically creates** the first tenant:

```bash
php artisan migrate:fresh --seed
```

The `OrganizationalUnitSeeder` handles:

1. Check if `TenantKey` exists
2. If not: generate KEK, create envelope keys, insert tenant record
3. Seed organizational structure with that `tenant_id`
4. Create test users linked to that tenant

**No manual provisioning needed** for dev/staging.

---

## Manual Provisioning (Production)

This section is relevant when onboarding **additional customer organizations** in production.

### Prerequisites

- ✅ SecPal deployed in multi-tenant mode ([Deployment Guide](/api/docs/guides/multi-tenant-deployment.md))
- ✅ Database migrations completed (`php artisan migrate`)
- ✅ KEK (Key Encryption Key) generated and secured
- ✅ SSH access to production server (or admin API access)

---

## Provisioning Methods

### Method 1: Automated Provisioning (Recommended)

Create an Artisan command for consistent tenant provisioning:

#### Step 1: Create Provisioning Command

```bash
php artisan make:command ProvisionTenant
```

#### Step 2: Implement Provisioning Logic

Edit `app/Console/Commands/ProvisionTenant.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision
                            {customer-name : Name of the customer/organization}
                            {admin-email : Email address for the admin user}
                            {--password= : Admin password (auto-generated if not provided)}
                            {--skip-email : Do not send welcome email}';

    protected $description = 'Provision a new tenant with encryption keys, admin user, and defaults';

    public function handle(): int
    {
        $customerName = $this->argument('customer-name');
        $adminEmail = $this->argument('admin-email');
        $password = $this->option('password') ?? Str::random(16);

        $this->info("🚀 Provisioning tenant: {$customerName}");
        $this->newLine();

        DB::beginTransaction();
        try {
            // Step 1: Create tenant with encryption keys
            $this->info('1️⃣  Creating tenant encryption keys...');
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
            $this->line("   ✅ Tenant created (ID: {$tenant->id})");

            // Step 2: Create admin user
            $this->info('2️⃣  Creating admin user...');
            $admin = User::create([
                'email' => $adminEmail,
                'password' => Hash::make($password),
                'name' => 'Admin',
                'tenant_id' => $tenant->id,
            ]);
            $this->line("   ✅ Admin user created (ID: {$admin->id})");

            // Step 3: Assign admin role (tenant-scoped)
            $this->info('3️⃣  Assigning admin role...');
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            $admin->assignRole('Admin');
            $this->line('   ✅ Admin role assigned');

            // Step 4: Create default organizational unit
            $this->info('4️⃣  Creating default organizational structure...');
            $orgUnit = OrganizationalUnit::create([
                'name' => 'Headquarters',
                'short_name' => 'HQ',
                'type' => 'branch',
                'tenant_id' => $tenant->id,
                'is_active' => true,
            ]);
            $this->line("   ✅ Organizational unit created (ID: {$orgUnit->id})");

            DB::commit();

            // Success summary
            $this->newLine();
            $this->info('🎉 Tenant provisioned successfully!');
            $this->newLine();

            $this->table(
                ['Property', 'Value'],
                [
                    ['Tenant ID', $tenant->id],
                    ['Customer Name', $customerName],
                    ['Admin Email', $adminEmail],
                    ['Admin Password', $password],
                    ['Organizational Unit', "HQ ({$orgUnit->id})"],
                ]
            );

            // Send welcome email
            if (!$this->option('skip-email')) {
                $this->info('📧 Sending welcome email...');
                // TODO: Implement email sending
                // Mail::to($adminEmail)->send(new WelcomeEmail($admin, $password));
                $this->warn('⚠️  Email sending not implemented yet. Send credentials manually.');
            }

            // Security reminder
            $this->newLine();
            $this->warn('⚠️  SECURITY REMINDER:');
            $this->warn('   - Store admin password securely (e.g., password manager)');
            $this->warn('   - Admin should change password after first login');
            $this->warn('   - Tenant encryption keys backed up automatically');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Tenant provisioning failed!');
            $this->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

#### Step 3: Use the Command

```bash
# Provision new tenant
php artisan tenant:provision "Customer Corp" "admin@customercorp.com"

# Output:
# 🚀 Provisioning tenant: Customer Corp
# 1️⃣  Creating tenant encryption keys...
#    ✅ Tenant created (ID: 1)
# 2️⃣  Creating admin user...
#    ✅ Admin user created (ID: 9d8e7f...)
# 3️⃣  Assigning admin role...
#    ✅ Admin role assigned
# 4️⃣  Creating default organizational structure...
#    ✅ Organizational unit created (ID: 1)
# 🎉 Tenant provisioned successfully!
#
# ┌─────────────────────┬────────────────────────────────────┐
# │ Property            │ Value                              │
# ├─────────────────────┼────────────────────────────────────┤
# │ Tenant ID           │ 1                                  │
# │ Customer Name       │ Customer Corp                      │
# │ Admin Email         │ admin@customercorp.com             │
# │ Admin Password      │ XyZ9aBc...                         │
# │ Organizational Unit │ HQ (1)                             │
# └─────────────────────┴────────────────────────────────────┘
```

---

### Method 2: Manual Provisioning (Tinker)

For quick manual provisioning during development:

```bash
php artisan tinker
```

```php
use App\Models\TenantKey;
use App\Models\User;
use App\Models\OrganizationalUnit;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

// 1. Create tenant
$tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
echo "Tenant created: ID = {$tenant->id}\n";

// 2. Create admin user
$admin = User::create([
    'email' => 'admin@tenant1.com',
    'password' => Hash::make('SecurePassword123!'),
    'name' => 'Admin User',
    'tenant_id' => $tenant->id,
]);
echo "Admin created: {$admin->email}\n";

// 3. Assign admin role (IMPORTANT: Set team ID first!)
app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
$admin->assignRole('Admin');
echo "Admin role assigned\n";

// 4. Create default organizational unit
$orgUnit = OrganizationalUnit::create([
    'name' => 'Headquarters',
    'short_name' => 'HQ',
    'type' => 'branch',
    'tenant_id' => $tenant->id,
    'is_active' => true,
]);
echo "Organizational unit created: {$orgUnit->name}\n";

echo "\n✅ Tenant provisioned successfully!\n";
echo "Tenant ID: {$tenant->id}\n";
echo "Admin: {$admin->email}\n";
```

---

### Method 3: API-Based Provisioning (Future)

For SaaS self-service registration (planned Phase 2):

```http
POST /api/v1/admin/tenants
Content-Type: application/json
Authorization: Bearer {SUPER_ADMIN_TOKEN}

{
  "customer_name": "Customer Corp",
  "admin_email": "admin@customercorp.com",
  "admin_name": "John Admin",
  "admin_password": "SecurePassword123!",
  "organizational_unit": {
    "name": "Headquarters",
    "short_name": "HQ"
  }
}
```

**Response:**

```json
{
  "tenant_id": 1,
  "admin_user_id": "9d8e7f6a-5b4c-3d2e-1f0e-9d8c7b6a5f4e",
  "admin_email": "admin@customercorp.com",
  "status": "active"
}
```

---

## Post-Provisioning Setup

### 1. Customize Organizational Structure

Add additional branches, departments, teams:

```bash
php artisan tinker
```

```php
use App\Models\OrganizationalUnit;

$tenantId = 1; // Replace with actual tenant ID
$hq = OrganizationalUnit::where('tenant_id', $tenantId)->where('name', 'Headquarters')->first();

// Add branch
$branch = OrganizationalUnit::create([
    'name' => 'Frankfurt Branch',
    'short_name' => 'FFM',
    'type' => 'branch',
    'parent_id' => $hq->id,
    'tenant_id' => $tenantId,
    'is_active' => true,
]);

// Add team
$team = OrganizationalUnit::create([
    'name' => 'Night Shift Team A',
    'short_name' => 'NSA',
    'type' => 'team',
    'parent_id' => $branch->id,
    'tenant_id' => $tenantId,
    'is_active' => true,
]);

echo "Organizational structure created:\n";
echo "- {$hq->name}\n";
echo "  └─ {$branch->name}\n";
echo "     └─ {$team->name}\n";
```

### 2. Create Additional Users

```bash
php artisan tinker
```

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

$tenantId = 1; // Replace with actual tenant ID

// Set team ID for RBAC
app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

// Create manager
$manager = User::create([
    'email' => 'manager@customercorp.com',
    'password' => Hash::make('password'),
    'name' => 'Branch Manager',
    'tenant_id' => $tenantId,
]);
$manager->assignRole('Manager');

// Create guard
$guard = User::create([
    'email' => 'guard@customercorp.com',
    'password' => Hash::make('password'),
    'name' => 'Security Guard',
    'tenant_id' => $tenantId,
]);
$guard->assignRole('Guard');

echo "Users created:\n";
echo "- Manager: {$manager->email}\n";
echo "- Guard: {$guard->email}\n";
```

### 3. Configure Customer & Site Data

```bash
php artisan tinker
```

```php
use App\Models\Customer;
use App\Models\Site;
use App\Models\OrganizationalUnit;

$tenantId = 1; // Replace with actual tenant ID
$orgUnit = OrganizationalUnit::where('tenant_id', $tenantId)->first();

// Create customer
$customer = Customer::create([
    'name' => 'Acme Corporation',
    'customer_number' => 'CUST-001',
    'tenant_id' => $tenantId,
    'is_active' => true,
    'address' => [
        'street' => 'Main Street 123',
        'city' => 'Frankfurt',
        'postal_code' => '60308',
        'country' => 'DE',
    ],
]);

// Create site
$site = Site::create([
    'name' => 'Acme HQ',
    'tenant_id' => $tenantId,
    'customer_id' => $customer->id,
    'organizational_unit_id' => $orgUnit->id,
    'type' => 'permanent',
    'address' => [
        'street' => 'Main Street 123',
        'city' => 'Frankfurt',
        'postal_code' => '60308',
        'country' => 'DE',
    ],
]);

echo "Customer & Site created:\n";
echo "- Customer: {$customer->name} ({$customer->customer_number})\n";
echo "- Site: {$site->name}\n";
```

---

## Verification Steps

After provisioning, verify tenant isolation:

### 1. Test Admin Login

```bash
# Login as admin
curl -X POST https://api.secpal.app/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@customercorp.com",
    "password": "SecurePassword123!"
  }'

# Response should include token
# {
#   "token": "1|XYZ...",
#   "user": {
#     "id": "9d8e7f...",
#     "email": "admin@customercorp.com",
#     "tenant_id": 1
#   }
# }
```

### 2. Verify Tenant Isolation

```bash
# Get admin token
TOKEN="1|XYZ..."

# List organizational units (should only see tenant's data)
curl -X GET https://api.secpal.app/v1/organizational-units \
  -H "Authorization: Bearer $TOKEN"

# Response should only include tenant's organizational units
# {
#   "data": [
#     {"id": 1, "name": "Headquarters", "tenant_id": 1}
#   ]
# }
```

### 3. Verify Admin Permissions

```bash
# Admin should have all permissions
curl -X GET https://api.secpal.app/v1/users/me \
  -H "Authorization: Bearer $TOKEN"

# Response includes roles
# {
#   "id": "9d8e7f...",
#   "email": "admin@customercorp.com",
#   "roles": [
#     {"name": "Admin"}
#   ],
#   "permissions": ["*"]
# }
```

---

## Troubleshooting

### Issue: "User has no assigned tenant"

**Symptom:** Admin user cannot login or gets 500 error

**Cause:** User created without `tenant_id`

**Fix:**

```php
$user = User::where('email', 'admin@customercorp.com')->first();
$user->update(['tenant_id' => 1]); // Assign to tenant
```

### Issue: "Role 'Admin' not found"

**Symptom:** Cannot assign admin role

**Cause:** Roles not seeded or team ID not set

**Fix:**

```bash
# 1. Seed roles
php artisan db:seed --class=RolePermissionSeeder

# 2. Set team ID before assigning role
php artisan tinker
```

```php
use Spatie\Permission\PermissionRegistrar;

$tenantId = 1;
app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

$admin = User::where('email', 'admin@customercorp.com')->first();
$admin->assignRole('Admin');
```

### Issue: "Cannot see organizational units"

**Symptom:** Admin cannot list organizational units

**Cause:** InjectTenantId middleware not applied or Policy blocking access

**Fix:**

```bash
# Verify middleware is applied
php artisan route:list | grep organizational-units

# Should show:
# GET|HEAD v1/organizational-units │ tenant.inject │ OrganizationalUnitController@index
```

---

## Automation & Integration

### Webhook Integration (Future)

Trigger provisioning from external systems:

```php
// app/Http/Controllers/WebhookController.php
public function provisionTenant(Request $request)
{
    $validated = $request->validate([
        'customer_name' => 'required|string',
        'admin_email' => 'required|email',
        'webhook_secret' => 'required|string',
    ]);

    // Verify webhook secret
    if ($validated['webhook_secret'] !== config('app.webhook_secret')) {
        abort(403, 'Invalid webhook secret');
    }

    // Provision tenant
    $result = (new TenantProvisioningService())->provisionTenant(
        $validated['customer_name'],
        $validated['admin_email']
    );

    return response()->json($result, 201);
}
```

### Monitoring Provisioning

Track provisioning success rate:

```php
// app/Observers/TenantKeyObserver.php
class TenantKeyObserver
{
    public function created(TenantKey $tenant): void
    {
        Log::info('Tenant provisioned', [
            'tenant_id' => $tenant->id,
            'key_version' => $tenant->key_version,
        ]);

        // Send notification to ops team
        // Notification::route('slack', config('slack.ops_channel'))
        //     ->notify(new TenantProvisionedNotification($tenant));
    }
}
```

---

## Security Considerations

### 1. Admin Password Policy

**Requirement:** Admin passwords must be:

- ✅ Minimum 12 characters
- ✅ Include uppercase, lowercase, numbers, symbols
- ✅ Not in common password lists
- ✅ Changed after first login

**Implementation:**

```php
// app/Rules/StrongPassword.php
class StrongPassword implements Rule
{
    public function passes($attribute, $value)
    {
        return strlen($value) >= 12
            && preg_match('/[A-Z]/', $value)
            && preg_match('/[a-z]/', $value)
            && preg_match('/[0-9]/', $value)
            && preg_match('/[^A-Za-z0-9]/', $value);
    }
}
```

### 2. Tenant Key Backup

**Requirement:** All tenant encryption keys must be backed up securely

**Implementation:**

```bash
#!/bin/bash
# backup-tenant-keys.sh

TENANT_ID=$1
BACKUP_DIR="/secure/backups/tenant-keys"
mkdir -p "$BACKUP_DIR"

# Export tenant keys (encrypted)
php artisan tinker --execute="
  \$tenant = \App\Models\TenantKey::find(${TENANT_ID});
  echo json_encode([
    'tenant_id' => \$tenant->id,
    'dek_wrapped' => base64_encode(\$tenant->dek_wrapped),
    'idx_wrapped' => base64_encode(\$tenant->idx_wrapped),
    'key_version' => \$tenant->key_version,
  ]);
" | gpg --encrypt --recipient ops@secpal.app \
  > "${BACKUP_DIR}/tenant-${TENANT_ID}-keys.gpg"

echo "Tenant keys backed up: ${BACKUP_DIR}/tenant-${TENANT_ID}-keys.gpg"
```

### 3. Audit Logging

Log all tenant provisioning events:

```php
// In ProvisionTenant command:
Log::info('Tenant provisioning started', [
    'customer_name' => $customerName,
    'admin_email' => $adminEmail,
    'initiated_by' => Auth::user()->email ?? 'system',
]);

// After success:
Log::info('Tenant provisioning completed', [
    'tenant_id' => $tenant->id,
    'admin_user_id' => $admin->id,
]);
```

---

## Provisioning Checklist

Before provisioning a new tenant:

- [ ] Customer name confirmed (legal entity name)
- [ ] Admin email verified (will receive credentials)
- [ ] Organizational structure requirements gathered
- [ ] Initial user list prepared (roles defined)
- [ ] Data migration plan (if migrating from another system)
- [ ] KEK backup verified (can restore if needed)
- [ ] Monitoring and alerting configured

After provisioning:

- [ ] Admin credentials sent securely (encrypted email/password manager)
- [ ] Admin login tested successfully
- [ ] Tenant isolation verified (cannot see other tenants)
- [ ] Organizational structure created
- [ ] Initial users created and roles assigned
- [ ] Backup scheduled for tenant data
- [ ] Customer onboarding documentation sent

---

## Related Documentation

- [Multi-Tenant Deployment Guide](/api/docs/guides/multi-tenant-deployment.md) - Production setup
- [Migration Guide: Single → Multi-Tenant](/api/docs/migration-guides/single-to-multi-tenant.md) - Upgrading existing deployments
- [RBAC Architecture](/api/docs/rbac-architecture.md) - Tenant-scoped permissions
- [ADR-008: User-Based Tenant Resolution](/.github/docs/adr/20251219-user-based-tenant-resolution.md) - Architecture decision

---

**Last Updated:** 2025-12-19
**Epic:** #357 (Production-Ready Multi-Tenant Architecture)
**Status:** ✅ Production Ready
