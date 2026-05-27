<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Production Deployment Guide

Complete guide for deploying SecPal API to production environments.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Environment Setup](#environment-setup)
- [Security Configuration](#security-configuration)
  - [1. Generate Key Encryption Key (KEK)](#1-generate-key-encryption-key-kek)
  - [2. File Permissions](#2-file-permissions)
  - [3. Public Passkey Challenge Contract](#3-public-passkey-challenge-contract)
  - [4. Passkey Enrollment Contract](#4-passkey-enrollment-contract)
- [Database Setup](#database-setup)
- [Tenant Key Initialization](#tenant-key-initialization)
- [Health Check Verification](#health-check-verification)
- [Post-Deployment Checklist](#post-deployment-checklist)
- [Troubleshooting](#troubleshooting)

---

## Prerequisites

### System Requirements

- **Operating System:** Linux (Ubuntu 22.04+ or Debian 12+ recommended)
- **PHP:** 8.4 or higher
- **Database:** PostgreSQL 15+ or 16+
- **Web Server:** Nginx or Apache with mod_rewrite
- **Composer:** 2.x
- **Git:** Latest stable version

### Required PHP Extensions

```bash
# Verify installed extensions
php -m | grep -E 'mbstring|xml|ctype|iconv|intl|pdo_pgsql|sodium'

# Install missing extensions (Ubuntu/Debian)
sudo apt-get install php8.4-mbstring php8.4-xml php8.4-intl php8.4-pgsql php8.4-sodium
```

### Server Access

- SSH access with sudo privileges
- PostgreSQL admin credentials
- Domain name configured (optional for initial setup)

---

## Environment Setup

### 1. Clone Repository

```bash
# Clone to production directory
cd /var/www
sudo git clone https://github.com/SecPal/api.git secpal-api
cd secpal-api

# Set proper ownership
sudo chown -R www-data:www-data /var/www/secpal-api
```

### 2. Install Dependencies

```bash
# Install PHP dependencies (production only, no dev dependencies)
composer install --no-dev --optimize-autoloader
```

### 3. Configure Environment

```bash
# Copy example environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 4. Edit Environment Variables

Edit `.env` with production values:

```env
# Application
APP_NAME=SecPal
APP_ENV=production
APP_DEBUG=false
APP_URL=https://customer-api.secpal.dev

# Public bootstrap (pre-login runtime discovery)
BOOTSTRAP_PUBLIC_ENABLED=true
BOOTSTRAP_INSTANCE_DISPLAY_NAME=Customer SecPal
BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION=1.4.0
BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD=10400
BOOTSTRAP_PASSWORD_LOGIN_ENABLED=true
BOOTSTRAP_PASSKEY_LOGIN_ENABLED=true
BOOTSTRAP_MANAGED_ANDROID_ENROLLMENT_ENABLED=false
BOOTSTRAP_ANDROID_PUSH_ENABLED=true
BOOTSTRAP_ANDROID_PUSH_METADATA_REVISION=3
BOOTSTRAP_ANDROID_PUSH_PUBLIC_API_KEY=public-client-api-key-demo-1234567890
BOOTSTRAP_ANDROID_PUSH_PUBLIC_PROJECT_ID=secpal-demo-push
BOOTSTRAP_ANDROID_PUSH_PUBLIC_APPLICATION_ID=1:1234567890:android:abcdef1234567890
BOOTSTRAP_ANDROID_PUSH_PUBLIC_SENDER_ID=1234567890
BOOTSTRAP_WEB_PUSH_ENABLED=true
BOOTSTRAP_WEB_PUSH_METADATA_REVISION=5
BOOTSTRAP_WEB_PUSH_PUBLIC_VAPID_KEY=BE9tfo-aCxwtPk9QYXKDlAUGBwgJCgsMDQ4PEBESExQVobLD1OX2BxgpMEFSY3SFlgcYKTBLXG1-j5ABAgMEBQY
# Customer-owned Android push delivery (backend-only)
ANDROID_PUSH_FCM_PROJECT_ID=customer-owned-push
ANDROID_PUSH_FCM_CLIENT_EMAIL=firebase-adminsdk-abc123@customer-owned-push.iam.gserviceaccount.com
ANDROID_PUSH_FCM_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nREPLACE_WITH_DEPLOYMENT_SECRET\n-----END PRIVATE KEY-----\n"
ANDROID_PUSH_FCM_TOKEN_URI=https://oauth2.googleapis.com/token
ANDROID_PUSH_FCM_API_BASE_URL=https://fcm.googleapis.com
ANDROID_PUSH_FCM_CONNECT_TIMEOUT=5
ANDROID_PUSH_FCM_TIMEOUT=10
# Customer-owned Web Push delivery (backend-only)
WEB_PUSH_VAPID_SUBJECT=mailto:notifications@customer.example
WEB_PUSH_VAPID_PRIVATE_KEY=REPLACE_WITH_BASE64URL_VAPID_PRIVATE_KEY
WEB_PUSH_DELIVERY_TTL=300
WEB_PUSH_DELIVERY_URGENCY=normal
WEB_PUSH_DELIVERY_CONNECT_TIMEOUT=5
WEB_PUSH_DELIVERY_TIMEOUT=20
BOOTSTRAP_RETRYABLE=true
BOOTSTRAP_RETRY_AFTER_SECONDS=60

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=secpal_production
DB_USERNAME=secpal_user
DB_PASSWORD=YOUR_SECURE_PASSWORD_HERE

# Envelope Encryption (configure after KEK generation)
KEK_PATH=/var/www/secpal-api/storage/keys/kek.key

# Sanctum (SPA Authentication)
SANCTUM_STATEFUL_DOMAINS=app.secpal.dev
SESSION_DOMAIN=.secpal.dev

# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noreply@secpal.app
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@secpal.app
MAIL_FROM_NAME="${APP_NAME}"

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

**Security Notes:**

- Use strong, unique passwords for database
- Set `APP_DEBUG=false` in production
- Configure `SESSION_DOMAIN` to match your domain
- Use environment-specific SMTP credentials

## Public Bootstrap

Expose the public runtime-discovery endpoint at `GET /v1/bootstrap` only after the deployment-local bootstrap values are configured.

- `APP_URL` must be the externally reachable API origin for this deployment. The endpoint derives the canonical `api_base_url` from `APP_URL` and appends `/v1`.
- `APP_NAME` is used as the public instance display name unless `BOOTSTRAP_INSTANCE_DISPLAY_NAME` overrides it.
- `BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION` and `BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD` are required. If either is missing, the endpoint fails closed with `500 BOOTSTRAP_STATE_INVALID` instead of falling back to SecPal-hosted defaults.
- Set `BOOTSTRAP_PUBLIC_ENABLED=false` when the deployment should return the documented `503 BOOTSTRAP_CONFIG_UNAVAILABLE` response instead of serving bootstrap metadata.
- `BOOTSTRAP_PASSWORD_LOGIN_ENABLED`, `BOOTSTRAP_PASSKEY_LOGIN_ENABLED`, `BOOTSTRAP_MANAGED_ANDROID_ENROLLMENT_ENABLED`, `BOOTSTRAP_ANDROID_PUSH_ENABLED`, and `BOOTSTRAP_WEB_PUSH_ENABLED` feed the public pre-login bootstrap feature flags. The canonical shared notification capability surface is `features.notification_channels`, which is exhaustive for the active backend schema.
- When `BOOTSTRAP_ANDROID_PUSH_ENABLED=true`, the public bootstrap response exposes canonical `notification_channels.android_fcm` runtime metadata with channel `android_fcm`, the deployment-defined `metadata_revision`, and the public Android client metadata fields required for runtime initialization.
- When Android FCM bootstrap is enabled, `BOOTSTRAP_ANDROID_PUSH_METADATA_REVISION`, `BOOTSTRAP_ANDROID_PUSH_PUBLIC_API_KEY`, `BOOTSTRAP_ANDROID_PUSH_PUBLIC_PROJECT_ID`, `BOOTSTRAP_ANDROID_PUSH_PUBLIC_APPLICATION_ID`, and `BOOTSTRAP_ANDROID_PUSH_PUBLIC_SENDER_ID` are all required. Missing values fail closed with `500 BOOTSTRAP_STATE_INVALID` and channel-aware `notification_channels.android_fcm.*` field paths.
- When `BOOTSTRAP_WEB_PUSH_ENABLED=true`, the same bootstrap response exposes canonical `notification_channels.web_push` runtime metadata with channel `web_push`, the deployment-defined `metadata_revision`, and the browser-safe public VAPID key required for Push API subscription creation.
- When Web Push bootstrap is enabled, `BOOTSTRAP_WEB_PUSH_METADATA_REVISION` and `BOOTSTRAP_WEB_PUSH_PUBLIC_VAPID_KEY` are required. Missing values fail closed with `500 BOOTSTRAP_STATE_INVALID` and channel-aware `notification_channels.web_push.*` field paths.
- Increment `BOOTSTRAP_ANDROID_PUSH_METADATA_REVISION` or `BOOTSTRAP_WEB_PUSH_METADATA_REVISION` whenever the corresponding public client metadata changes so authenticated installation updates with stale bootstrap state can be rejected deterministically.
- Only public runtime metadata belongs here. Never place server credentials, service-account JSON, private VAPID material, or delivery secrets in bootstrap configuration.
- Customer-owned Android push delivery credentials stay backend-only in `ANDROID_PUSH_FCM_*`, and Web Push private VAPID material must stay server-side; neither surface is read from or exposed through the bootstrap payload.

Verify the deployed response with the canonical public origin:

```bash
curl --fail 'https://customer-api.secpal.dev/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400'
curl --fail 'https://customer-api.secpal.dev/v1/bootstrap?client_platform=browser'
```

### Authenticated Notification Installations

Authenticated Android and browser clients register one stable installation identifier against the selected customer-hosted backend:

- `PUT /v1/me/notification-installations/{installationId}` is the canonical create/update surface for both Android FCM (`channel=android_fcm`) and browser Web Push (`channel=web_push`).
- `DELETE /v1/me/notification-installations/{installationId}` is the canonical revocation surface for logout, uninstall, browser sign-out, or explicit cleanup.
- Android clients must echo the current `notification_channels.android_fcm.metadata_revision` from `GET /v1/bootstrap` in `runtime.metadata_revision`.
- Browser clients must echo the current `notification_channels.web_push.metadata_revision` from `GET /v1/bootstrap?client_platform=browser` in `runtime.metadata_revision`, use one stable `installationId` per notification-enabled browser profile/origin, and authenticate through either bearer tokens or the first-party browser session plus CSRF flow.
- Disabled channels fail closed with `409 NOTIFICATION_CHANNEL_UNSUPPORTED` and the requested channel name. Stale runtime metadata fails closed with `409 NOTIFICATION_RUNTIME_STATE_INVALID` and the expected channel revision details.

### Customer-Owned Android Push Delivery

Customer-hosted deployments can send Android push directly from the backend without any SecPal-operated relay.

- Configure `ANDROID_PUSH_FCM_PROJECT_ID`, `ANDROID_PUSH_FCM_CLIENT_EMAIL`, and `ANDROID_PUSH_FCM_PRIVATE_KEY` on the API deployment. These values are required for server-side FCM HTTP v1 delivery and stay backend-only.
- `ANDROID_PUSH_FCM_PRIVATE_KEY` may be provided either as a real multiline secret or as a single-line value containing literal `\n` escapes exactly like the example above.
- Missing or invalid `ANDROID_PUSH_FCM_*` credentials fail closed during delivery. The backend does not fall back to SecPal-owned routing when customer-owned delivery is selected.
- The queueable send primitive is `App\Jobs\DeliverAndroidPushMessage`; it runs on the default queue worker. Ensure the deployment's default queue worker is active before relying on asynchronous delivery.
- Delivery outcomes that indicate a stale or invalid registration token (`UNREGISTERED`, `SENDER_ID_MISMATCH`, or token-specific invalid-argument responses) delete the stored registration so the next authenticated app session must re-register with fresh runtime metadata.

### Customer-Owned Web Push Delivery

Customer-hosted deployments can send browser Web Push directly from the backend without any SecPal-operated relay.

- Configure backend-only `WEB_PUSH_VAPID_SUBJECT` and `WEB_PUSH_VAPID_PRIVATE_KEY` on the API deployment. The delivery path reuses `BOOTSTRAP_WEB_PUSH_PUBLIC_VAPID_KEY` as the matching public VAPID key advertised to browsers, so the public and private values must belong to the same key pair.
- `WEB_PUSH_VAPID_PRIVATE_KEY` must be the base64url-encoded VAPID private key expected by the audited `minishlink/web-push` library, not a PEM block.
- `WEB_PUSH_DELIVERY_TTL`, `WEB_PUSH_DELIVERY_URGENCY`, `WEB_PUSH_DELIVERY_CONNECT_TIMEOUT`, and `WEB_PUSH_DELIVERY_TIMEOUT` tune backend delivery runtime defaults without exposing anything new through `GET /v1/bootstrap`.
- Missing or invalid `WEB_PUSH_*` delivery credentials fail closed during delivery. The backend does not fall back to SecPal-owned routing when customer-owned delivery is selected.
- The tenant-scoped queue fan-out primitive is `App\Services\QueueWebPushDeliveryService::dispatchToRegistrations()`. It deduplicates the targeted registration id list per tenant and enqueues `App\Jobs\DeliverWebPushMessage` on the default queue worker.
- Delivery outcomes that indicate a stale browser subscription (stored subscription corruption, local `subscription_expires_at`, or push-service `404` / `410` feedback) delete the stored registration so the next authenticated browser session must re-register with fresh runtime metadata.
- Full subscription endpoints, `p256dh`, `auth`, and private VAPID material stay server-side. API resources expose only `subscription_endpoint_origin`, and bootstrap responses never include server-side delivery secrets.

Example registration request:

```bash
curl --fail-with-body \
  -X PUT 'https://customer-api.secpal.dev/v1/me/notification-installations/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b' \
  -H 'Authorization: Bearer YOUR_API_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "channel": "android_fcm",
    "installation_name": "SM-G556B reception tablet",
    "lifecycle_event": "registered",
    "runtime": {
      "bootstrap_version": "v1",
      "schema_version": 3,
      "metadata_revision": 3
    },
    "registration": {
      "push_token": "e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef",
      "app": {
        "package_name": "app.secpal",
        "package_version_name": "1.5.0",
        "package_version_code": 10500
      },
      "device": {
        "manufacturer": "Samsung",
        "model": "SM-G556B",
        "android_version": "16",
        "sdk_int": 36
      }
    }
  }'
```

---

## Security Configuration

### 1. Generate Key Encryption Key (KEK)

The KEK is the master key for envelope encryption. Generate it securely:

```bash
# Create keys directory
mkdir -p storage/keys

# Generate 256-bit random KEK
php -r "file_put_contents('storage/keys/kek.key', random_bytes(32));"

# Set restrictive permissions (CRITICAL)
chmod 0600 storage/keys/kek.key
chown www-data:www-data storage/keys/kek.key
```

#### KEK Security (CRITICAL)

**⚠️ IMPORTANT:** The Key Encryption Key (KEK) must be:

- **Permissions:** Must be `0600` (read/write by owner only)
- **Ownership:** Must be owned by web server user (e.g., `www-data`)
- **Backup:** Store encrypted backup in secure offline location
- **Never commit:** Already in `.gitignore`, but verify before pushing

**Production Best Practice:**

Store KEK outside web root:

```bash
# Move KEK to secure location
sudo mkdir -p /etc/secpal/keys
sudo mv storage/keys/kek.key /etc/secpal/keys/
sudo chmod 0600 /etc/secpal/keys/kek.key
sudo chown www-data:www-data /etc/secpal/keys/kek.key

# Update .env
KEK_PATH=/etc/secpal/keys/kek.key
```

### 2. File Permissions

```bash
# Set proper directory permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Restrict access to .env
sudo chmod 0600 .env
sudo chown www-data:www-data .env
```

### 3. Public Passkey Challenge Contract

The public passkey challenge endpoints (`POST /v1/auth/passkeys/challenges`,
`POST /v1/auth/token/passkeys/challenges`) are now **discoverable-only**:
clients must start passkey sign-in without identifying the user up front.

Operationally this means:

- requests must **not** include `email`
- challenge responses must not rely on `public_key.allow_credentials`
- browser and native clients should invoke the platform discoverable-passkey
  picker and let the authenticator return the user handle during verification

Legacy email-scoped startup payloads now fail validation. The API no longer
generates public fallback credential descriptors or requires a dedicated
passkey-authentication fallback secret for these endpoints.

### 4. Passkey Enrollment Contract

To match the discoverable-only login contract above, passkey **enrollment** at
`POST /v1/me/passkeys/challenges/registration` now defaults to
**discoverable / resident credentials**:

- `authenticator_selection.resident_key` defaults to `required`
- `authenticator_selection.require_resident_key` defaults to `true`
- WebAuthn enrollment fails on authenticators that cannot store a discoverable
  credential for the relying party

The defaults are configurable via the optional `PASSKEY_RESIDENT_KEY` and
`PASSKEY_REQUIRE_RESIDENT_KEY` environment variables, but the application now
**ignores** unrecognized `PASSKEY_RESIDENT_KEY` values (anything other than
`required`, `preferred`, or `discouraged`) and falls back to `required`.
Whenever `PASSKEY_RESIDENT_KEY=required`, `require_resident_key` is forced to
`true` regardless of `PASSKEY_REQUIRE_RESIDENT_KEY`, so a misconfigured pair
cannot silently re-enable non-discoverable enrollment.

**`PASSKEY_REQUIRE_RESIDENT_KEY` coupling by `resident_key` value:**

| `PASSKEY_RESIDENT_KEY` | `PASSKEY_REQUIRE_RESIDENT_KEY` unset | Effective `require_resident_key`                                    |
| ---------------------- | ------------------------------------ | ------------------------------------------------------------------- |
| `required` (default)   | —                                    | `true` (always forced)                                              |
| `preferred`            | unset                                | `true` (config default)                                             |
| `preferred`            | `false`                              | `false`                                                             |
| `discouraged`          | —                                    | `false` (always forced — the pair is a WebAuthn spec contradiction) |

When `PASSKEY_RESIDENT_KEY=discouraged`, `require_resident_key` is **always
`false`** regardless of `PASSKEY_REQUIRE_RESIDENT_KEY`; setting both
`discouraged` + `require_resident_key=true` is forbidden by the WebAuthn Level 2
spec (§5.4.6) and the application enforces this at runtime.

**Operator and user impact for existing non-discoverable credentials:**

Any passkey credential previously enrolled when `resident_key` was `preferred`
and the authenticator chose not to store a discoverable credential is **no
longer usable** for the public, discoverable-only login flow. There is no
public, email-scoped recovery path: re-introducing one would re-open the
enumeration vector that the discoverable-only contract closed.

Affected users must:

1. Sign in with their primary credentials (email + password) and complete MFA
   if enrolled, then
2. Open the authenticated passkey management endpoints
   (`GET /v1/me/passkeys` / `POST /v1/me/passkeys/challenges/registration`),
3. Enroll a **new** discoverable passkey on a supported authenticator, and
4. Delete the legacy non-discoverable credential through
   `DELETE /v1/me/passkeys/{id}` if desired.

Operators should communicate the re-enrollment requirement in their release
notes. The `passkey_credentials` schema does **not** store a discoverability
flag, so there is no query that can definitively single out non-discoverable
credentials. Instead, operators can inventory credentials enrolled **before**
the rollout of this change as a `created_at`-based cutoff heuristic — every
credential in that bucket was enrolled under the old `resident_key=preferred`
default and may or may not be discoverable depending on the authenticator that
created it:

```sql
-- Inventory passkey credentials enrolled before the discoverable-only rollout.
-- This is NOT an authoritative list of non-discoverable credentials — the
-- schema does not record discoverability — but every row here was enrolled
-- under the old resident_key=preferred default, so the authenticator may have
-- chosen to skip discoverable storage. Treat the result as the at-risk cohort
-- that should be notified to re-enroll.
SELECT user_id, credential_id, created_at, last_used_at
FROM passkey_credentials
WHERE created_at < '<discoverable-only deployment timestamp, e.g. 2026-05-21 00:00:00+00>'
ORDER BY created_at;
```

Replace the placeholder with the actual UTC timestamp at which this release was
deployed in the target environment. Operators with strict compliance
requirements can also drop the `WHERE` clause and prompt **all** existing
passkey users to re-enroll, trading a wider blast radius for a clean cutoff.

---

## Database Setup

### 1. Create Production Database

```bash
# Connect to PostgreSQL
sudo -u postgres psql

# Create database and user
CREATE DATABASE secpal_production;
CREATE USER secpal_user WITH ENCRYPTED PASSWORD 'YOUR_SECURE_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON DATABASE secpal_production TO secpal_user;

# Exit psql
\q
```

### 2. Run Migrations

```bash
# Run database migrations
php artisan migrate --force

# Verify migrations
php artisan migrate:status
```

**Expected Output:**

```txt
Migration name ......................................... Batch / Status
2014_10_12_000000_create_users_table ....................... [1] Ran
2025_11_15_create_tenant_keys_table ........................ [1] Ran
...
```

### 3. Seed Predefined Roles (RBAC)

```bash
# Seed 7 predefined roles (Employee, Employee Read Only, HR, Manager, Guard, Client, Works Council)
php artisan db:seed --class=RolesAndPermissionsSeeder
```

---

## Tenant Key Initialization

SecPal uses envelope encryption with tenant-specific keys. Initialize them:

### 1. Run Tenant Setup Command

```bash
php artisan tenant:setup
```

**Expected Output:**

```txt
SecPal Tenant Key Setup
=======================

✅ Checking KEK file... Found
✅ Generating tenant keys... Done
✅ Wrapping with KEK... Done
✅ Storing in database... Done

Tenant key setup complete!
   Tenant ID: 1
   Key version: 1

Verify setup with:
   php artisan app:validate-setup
```

**⚠️ Idempotent Design:**

Running `tenant:setup` multiple times is safe - it detects existing keys and aborts gracefully.

### 2. Validate Complete Setup

```bash
php artisan app:validate-setup
```

**Expected Output:**

```txt
SecPal Application Setup Validation
====================================

✅ Database Connection: OK
✅ Tenant Key Exists ..... OK (ID: 1, Version: 1)
✅ KEK File Readable ..... OK
✅ KEK Unwraps DEK ....... OK
✅ Storage Writable ....... OK

All checks passed! Application is ready.
```

**If Validation Fails:**

See [Troubleshooting](#troubleshooting) section below.

---

## Health Check Verification

### 1. Liveness Probe (Application Running)

```bash
curl http://localhost/health/live
```

**Expected Response (200 OK):**

```json
{
  "status": "ok",
  "timestamp": "2025-11-27T12:00:00Z"
}
```

### 2. Readiness Probe (Application Ready)

```bash
curl http://localhost/health/ready
```

**Expected Response (200 OK):**

```json
{
  "status": "ready",
  "checks": {
    "database": "ok",
    "tenant_key": "ok"
  },
  "timestamp": "2025-11-27T12:00:00Z"
}
```

**⚠️ If 503 Service Unavailable:**

Readiness probe returns 503 if:

- Database connection fails
- Tenant keys are missing (run `php artisan tenant:setup`)

See [Troubleshooting](#troubleshooting) for solutions.

---

## Post-Deployment Checklist

After deployment, verify these critical functions:

### 1. Authentication Works

```bash
# Test login (replace with actual credentials)
curl -X POST http://localhost/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@secpal.app",
    "password": "your_password"
  }'
```

**Expected:** 200 OK with access token.

### 2. Authenticated API Works

```bash
# Test authenticated profile endpoint (requires Bearer token)
curl http://localhost/v1/me \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

**Expected:** 200 OK with the authenticated user payload.

### 3. Health Checks Return 200

```bash
curl http://localhost/health/ready
curl http://localhost/health/live
```

**Expected:** Both return 200 OK.

### 4. Monitor Logs for Errors

```bash
# Check Laravel logs
tail -f storage/logs/laravel.log

# Check web server logs
sudo tail -f /var/log/nginx/error.log  # Nginx
sudo tail -f /var/log/apache2/error.log  # Apache
```

**Expected:** No critical errors.

---

## Troubleshooting

### Health Check Returns 503

**Problem:** `/health/ready` returns 503 Service Unavailable.

**Solutions:**

1. **Missing Tenant Keys**

   ```bash
   php artisan tenant:setup
   php artisan app:validate-setup
   ```

2. **Database Connection Failed**

   ```bash
   # Test database connection
   php artisan tinker
   >>> DB::connection()->getPdo();

   # Verify .env database credentials
   cat .env | grep DB_
   ```

3. **KEK File Not Readable**

   ```bash
   # Verify KEK file exists and has correct permissions
   ls -la /path/to/kek.key
   # Should show: -rw------- 1 www-data www-data 32 ...

   # Fix permissions if needed
   sudo chmod 0600 /path/to/kek.key
   sudo chown www-data:www-data /path/to/kek.key
   ```

### Authenticated API Returns 503

**Problem:** `/v1/me` returns 503 "No tenant keys available".

**Solution:**

```bash
# Initialize tenant keys
php artisan tenant:setup

# Verify setup
php artisan app:validate-setup
```

### KEK Permissions Warning

**Problem:** Setup validation warns about insecure KEK permissions.

**Solution:**

```bash
# Set correct permissions
sudo chmod 0600 /path/to/kek.key
sudo chown www-data:www-data /path/to/kek.key
```

### Database Migration Fails

**Problem:** `php artisan migrate` fails with permission errors.

**Solution:**

```bash
# Grant all privileges to database user
sudo -u postgres psql
GRANT ALL PRIVILEGES ON DATABASE secpal_production TO secpal_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO secpal_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO secpal_user;
\q
```

### Storage Directory Not Writable

**Problem:** Setup validation fails on storage writable check.

**Solution:**

```bash
# Set correct permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

---

## Additional Resources

- [Deployment Checklist](./deployment-checklist.md) - Quick reference checklist
- [Uberspace Deployment Guide](./deployment-uberspace.md) - Uberspace-specific instructions

---

## Security Reminders

**NEVER commit to git:**

- ❌ `.env` file (contains secrets)
- ❌ `storage/keys/kek.key` (master encryption key)
- ❌ `storage/logs/*.log` (may contain sensitive data)

**Always verify:**

- ✅ KEK file has `0600` permissions
- ✅ `.env` has `0600` permissions
- ✅ `APP_DEBUG=false` in production
- ✅ Health checks return 200 OK before going live

---

**Last Updated:** November 27, 2025
**For Support:** <https://github.com/SecPal/api/issues>
