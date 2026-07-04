<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Tenant Provisioning Guide

Step-by-step guide for provisioning new tenants (customers) in SecPal's multi-tenant environment.

## Overview

**Tenant provisioning** is the process of onboarding a new customer to SecPal. Each tenant gets:

1. ✅ **Unique encryption keys** (DEK + IDX for envelope encryption)
2. ✅ **Bootstrap user account** with explicit permissions and scoped access
3. ✅ **Default organizational structure** (HQ organizational unit)
4. ✅ **Predefined roles** (Employee, Employee Read Only, HR, Manager, Guard, Client, Works Council)
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

- ✅ SecPal deployed in multi-tenant mode ([Deployment Guide](/docs/guides/multi-tenant-deployment.md))
- ✅ Database migrations completed (`php artisan migrate`)
- ✅ KEK (Key Encryption Key) generated and secured
- ✅ SSH access to production server (or provisioning API access)

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
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision
                            {customer-name : Name of the customer/organization}
                            {bootstrap-email : Email address for the initial bootstrap user}
                            {--password= : Initial password (auto-generated if not provided)}
                            {--skip-email : Do not send welcome email}';

    protected $description = 'Provision a new tenant with encryption keys, a bootstrap user, and default scoped access';

    public function handle(): int
    {
        $customerName = $this->argument('customer-name');
        $bootstrapEmail = $this->argument('bootstrap-email');
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

            // Step 2: Create bootstrap user
            $this->info('2️⃣  Creating bootstrap user...');
            $bootstrapUser = User::create([
                'email' => $bootstrapEmail,
                'password' => Hash::make($password),
                'name' => 'Tenant Bootstrap User',
                'tenant_id' => $tenant->id,
            ]);
            $this->line("   ✅ Bootstrap user created (ID: {$bootstrapUser->id})");

            // Step 3: Create default organizational unit
            $this->info('3️⃣  Creating default organizational structure...');
            $orgUnit = OrganizationalUnit::create([
                'name' => 'Headquarters',
                'type' => 'branch',
                'tenant_id' => $tenant->id,
            ]);
            $this->line("   ✅ Organizational unit created (ID: {$orgUnit->id})");

            // Step 4: Grant explicit permissions and a root manage scope
            $this->info('4️⃣  Granting bootstrap permissions and root scope...');
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            /** @var list<string> $permissionNames */
            $permissionNames = Permission::query()
                ->where('guard_name', 'sanctum')
                ->pluck('name')
                ->all();

            $bootstrapUser->syncPermissions($permissionNames);

            UserInternalOrganizationalScope::updateOrCreate(
                [
                    'user_id' => $bootstrapUser->id,
                    'organizational_unit_id' => $orgUnit->id,
                    'min_viewable_rank' => null,
                    'max_viewable_rank' => null,
                    'min_assignable_rank' => null,
                    'max_assignable_rank' => null,
                ],
                [
                    'access_level' => 'manage',
                    'include_descendants' => true,
                    'allow_self_access' => true,
                ]
            );
            $this->line('   ✅ Bootstrap permissions and root manage scope granted');

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
                    ['Bootstrap Email', $bootstrapEmail],
                    ['Bootstrap Password', $password],
                    ['Organizational Unit', "{$orgUnit->name} ({$orgUnit->id})"],
                ]
            );

            // Send welcome email
            if (!$this->option('skip-email')) {
                $this->info('📧 Sending welcome email...');
                // TODO: Implement email sending
                // Mail::to($bootstrapEmail)->send(new WelcomeEmail($bootstrapUser, $password));
                $this->warn('⚠️  Email sending not implemented yet. Send credentials manually.');
            }

            // Security reminder
            $this->newLine();
            $this->warn('⚠️  SECURITY REMINDER:');
            $this->warn('   - Store the bootstrap password securely (e.g., password manager)');
            $this->warn('   - Bootstrap user should rotate the password after first login');
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
php artisan tenant:provision "Customer Corp" "ops@customercorp.com"

# Output:
# 🚀 Provisioning tenant: Customer Corp
# 1️⃣  Creating tenant encryption keys...
#    ✅ Tenant created (ID: 1)
# 2️⃣  Creating bootstrap user...
#    ✅ Bootstrap user created (ID: 9d8e7f...)
# 3️⃣  Creating default organizational structure...
#    ✅ Organizational unit created (ID: 1)
# 4️⃣  Granting bootstrap permissions and root scope...
#    ✅ Bootstrap permissions and root manage scope granted
# 🎉 Tenant provisioned successfully!
#
# ┌─────────────────────┬────────────────────────────────────┐
# │ Property            │ Value                              │
# ├─────────────────────┼────────────────────────────────────┤
# │ Tenant ID           │ 1                                  │
# │ Customer Name       │ Customer Corp                      │
# │ Bootstrap Email     │ ops@customercorp.com               │
# │ Bootstrap Password  │ XyZ9aBc...                         │
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
use App\Models\Permission;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

// 1. Create tenant
$tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
echo "Tenant created: ID = {$tenant->id}\n";

// 2. Create bootstrap user
$bootstrapUser = User::create([
    'email' => 'ops@tenant1.com',
    'password' => Hash::make('SecurePassword123!'),
    'name' => 'Tenant Bootstrap User',
    'tenant_id' => $tenant->id,
]);
echo "Bootstrap user created: {$bootstrapUser->email}\n";

// 3. Create default organizational unit
$orgUnit = OrganizationalUnit::create([
    'name' => 'Headquarters',
    'type' => 'branch',
    'tenant_id' => $tenant->id,
]);
echo "Organizational unit created: {$orgUnit->name}\n";

// 4. Grant explicit permissions and a root manage scope
app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
$bootstrapUser->syncPermissions(
    Permission::query()->where('guard_name', 'sanctum')->pluck('name')->all()
);

UserInternalOrganizationalScope::updateOrCreate(
    [
        'user_id' => $bootstrapUser->id,
        'organizational_unit_id' => $orgUnit->id,
        'min_viewable_rank' => null,
        'max_viewable_rank' => null,
        'min_assignable_rank' => null,
        'max_assignable_rank' => null,
    ],
    [
        'access_level' => 'manage',
        'include_descendants' => true,
        'allow_self_access' => true,
    ]
);
echo "Bootstrap permissions and root scope granted\n";

echo "\n✅ Tenant provisioned successfully!\n";
echo "Tenant ID: {$tenant->id}\n";
echo "Bootstrap user: {$bootstrapUser->email}\n";
```

---

### Method 3: API-Based Provisioning (Future)

For SaaS self-service registration (planned Phase 2):

```http
POST /api/v1/provisioning/tenants
Content-Type: application/json
Authorization: Bearer {TENANT_PROVISIONING_TOKEN}

{
  "customer_name": "Customer Corp",
    "bootstrap_email": "ops@customercorp.com",
    "bootstrap_name": "Initial Operator",
    "bootstrap_password": "SecurePassword123!",
  "organizational_unit": {
    "name": "Headquarters",
        "type": "branch"
  }
}
```

**Response:**

```json
{
  "tenant_id": 1,
  "bootstrap_user_id": "9d8e7f6a-5b4c-3d2e-1f0e-9d8c7b6a5f4e",
  "bootstrap_email": "ops@customercorp.com",
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
$hq = OrganizationalUnit::where('tenant_id', $tenantId)
    ->where('name', 'Headquarters')
    ->firstOrFail();

// Add branch
$branch = OrganizationalUnit::create([
    'name' => 'Frankfurt Branch',
    'type' => 'branch',
    'tenant_id' => $tenantId,
]);
$branch->setParent($hq);

// Add team via the closure-table hierarchy
$team = OrganizationalUnit::create([
    'name' => 'Night Shift Team A',
    'type' => 'custom',
    'custom_type_name' => 'Team',
    'tenant_id' => $tenantId,
]);
$team->setParent($branch);

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
    'email' => 'manager@tenant1.com',
    'password' => Hash::make('password'),
    'name' => 'Branch Manager',
    'tenant_id' => $tenantId,
]);
$manager->assignRole('Manager');

// Create guard
$guard = User::create([
    'email' => 'guard@tenant1.com',
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

### 1. Test Bootstrap User Login

```bash
# Login as the bootstrap user
curl -X POST https://api.secpal.dev/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{
        "email": "ops@customercorp.com",
    "password": "SecurePassword123!"
  }'

# Response should include token
# {
#   "token": "1|XYZ...",
#   "user": {
#     "id": "9d8e7f...",
#     "email": "ops@customercorp.com",
#     "tenant_id": 1
#   }
# }
```

### 2. Verify Tenant Isolation

```bash
# Get bootstrap user token
TOKEN="1|XYZ..."

# List organizational units (should only see tenant's data)
curl -X GET https://api.secpal.dev/v1/organizational-units \
  -H "Authorization: Bearer $TOKEN"

# Response should only include tenant's organizational units
# {
#   "data": [
#     {"id": 1, "name": "Headquarters", "tenant_id": 1}
#   ]
# }
```

### 3. Verify Bootstrap Permissions

```bash
# Bootstrap user should expose explicit permissions and organizational scopes
curl -X GET https://api.secpal.dev/v1/me \
  -H "Authorization: Bearer $TOKEN"

# Response includes direct permissions and scope metadata
# {
#   "id": "9d8e7f...",
#   "email": "ops@customercorp.com",
#   "roles": [],
#   "permissions": ["employees.read", "roles.create", "permissions.assign_direct", "..."],
#   "hasOrganizationalScopes": true
# }
```

---

## Troubleshooting

### Issue: "User has no assigned tenant"

**Symptom:** Bootstrap user cannot login or gets 500 error

**Cause:** User created without `tenant_id`

**Fix:**

```php
$user = User::where('email', 'ops@customercorp.com')->first();
$user->update(['tenant_id' => 1]); // Assign to tenant
```

### Issue: "Bootstrap user cannot manage tenant resources"

**Symptom:** Newly provisioned user cannot create roles, assign permissions, or review onboarding

**Cause:** Permissions were not granted directly or the tenant team ID was not set before assignment

**Fix:**

```bash
# 1. Seed roles
php artisan db:seed --class=RolesAndPermissionsSeeder

# 2. Set team ID before granting permissions
php artisan tinker
```

```php
use App\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

$tenantId = 1;
app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

$bootstrapUser = User::where('email', 'ops@customercorp.com')->first();
$bootstrapUser->syncPermissions(
    Permission::query()->where('guard_name', 'sanctum')->pluck('name')->all()
);
```

### Issue: "Cannot see organizational units"

**Symptom:** Bootstrap user cannot list organizational units

**Cause:** Root `manage` scope is missing or `InjectTenantId` middleware is not active

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
        'bootstrap_email' => 'required|email',
        'webhook_secret' => 'required|string',
    ]);

    // Verify webhook secret
    if ($validated['webhook_secret'] !== config('app.webhook_secret')) {
        abort(403, 'Invalid webhook secret');
    }

    // Provision tenant
    $result = (new TenantProvisioningService())->provisionTenant(
        $validated['customer_name'],
        $validated['bootstrap_email']
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

### 1. Bootstrap Credential Policy

**Requirement:** Initial bootstrap credentials must be:

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
    'bootstrap_email' => $bootstrapEmail,
    'initiated_by' => Auth::user()->email ?? 'system',
]);

// After success:
Log::info('Tenant provisioning completed', [
    'tenant_id' => $tenant->id,
    'bootstrap_user_id' => $bootstrapUser->id,
]);
```

---

## Provisioning Checklist

Before provisioning a new tenant:

- [ ] Customer name confirmed (legal entity name)
- [ ] Bootstrap user email verified (will receive initial credentials)
- [ ] Organizational structure requirements gathered
- [ ] Initial user list prepared (roles defined)
- [ ] Data migration plan (if migrating from another system)
- [ ] KEK backup verified (can restore if needed)
- [ ] Monitoring and alerting configured

After provisioning:

- [ ] Bootstrap credentials sent securely (encrypted email/password manager)
- [ ] Bootstrap login tested successfully
- [ ] Tenant isolation verified (cannot see other tenants)
- [ ] Organizational structure created
- [ ] Initial users created and roles assigned
- [ ] Backup scheduled for tenant data
- [ ] Customer onboarding documentation sent

---

## Related Documentation

- [Multi-Tenant Deployment Guide](/docs/guides/multi-tenant-deployment.md) - Production setup
- [Migration Guide: Single → Multi-Tenant](/docs/migration-guides/single-to-multi-tenant.md) - Upgrading existing deployments
- [RBAC Architecture](/docs/rbac-architecture.md) - Tenant-scoped permissions
- [ADR-008: User-Based Tenant Resolution](/.github/docs/adr/20251219-user-based-tenant-resolution.md) - Architecture decision

---

**Last Updated:** 2025-12-19
**Epic:** #357 (Production-Ready Multi-Tenant Architecture)
**Status:** ✅ Production Ready
