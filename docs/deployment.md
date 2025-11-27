<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Production Deployment Guide

Complete guide for deploying SecPal API to production environments.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Environment Setup](#environment-setup)
- [Security Configuration](#security-configuration)
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
APP_URL=https://api.secpal.app

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
SANCTUM_STATEFUL_DOMAINS=app.secpal.app,localhost:5173
SESSION_DOMAIN=.secpal.app

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

**⚠️ CRITICAL: KEK Security**

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

```
Migration name ......................................... Batch / Status
2014_10_12_000000_create_users_table ....................... [1] Ran
2025_11_15_create_tenant_keys_table ........................ [1] Ran
...
```

### 3. Seed Predefined Roles (RBAC)

```bash
# Seed 5 predefined roles (Admin, Manager, Guard, Client, Works Council)
php artisan db:seed --class=RoleSeeder
```

---

## Tenant Key Initialization

SecPal uses envelope encryption with tenant-specific keys. Initialize them:

### 1. Run Tenant Setup Command

```bash
php artisan tenant:setup
```

**Expected Output:**

```
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

```
SecPal Application Setup Validation
====================================

✅ Database Connection ... OK
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
curl -X POST http://localhost/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@secpal.app",
    "password": "your_password"
  }'
```

**Expected:** 200 OK with access token.

### 2. Secrets API Works

```bash
# Test secrets endpoint (requires Bearer token)
curl http://localhost/api/v1/secrets \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

**Expected:** 200 OK with empty array (no secrets yet).

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

### Secrets API Returns 503

**Problem:** `/api/v1/secrets` returns 503 "No tenant keys available".

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
- [Key Rotation Guide](./guides/key-rotation.md) - KEK and DEK rotation procedures
- [RBAC Setup](./guides/role-management.md) - Role and permission configuration
- [Health Check API](./api/health-checks.md) - Health check endpoint documentation

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
**For Support:** https://github.com/SecPal/api/issues
