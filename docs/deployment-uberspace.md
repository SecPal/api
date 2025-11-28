<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Deployment Guide: Uberspace

SecPal API deployment guide specifically for [Uberspace](https://uberspace.de) shared hosting.

**Prerequisites:** Active Uberspace account (<https://uberspace.de>)

---

## Table of Contents

- [SSH Setup](#ssh-setup)
- [PHP Version Selection](#php-version-selection)
- [PostgreSQL Setup](#postgresql-setup)
- [Application Deployment](#application-deployment)
- [Testing the Deployment](#testing-the-deployment)
- [Deployment Updates](#deployment-updates)
- [Troubleshooting](#troubleshooting)
- [Uberspace-Specific Commands](#uberspace-specific-commands)
- [Backup and Security](#backup-and-security)
- [Troubleshooting](#troubleshooting)

---

## SSH Setup

### 1. Connect to Your Uberspace

```bash
ssh <username>@<username>.uber.space
```

Example: `ssh alice@alice.uber.space`

### 2. Verify Home Directory

```bash
pwd
# Output: /home/<username>
```

---

## PHP Version Selection

Uberspace supports multiple PHP versions. SecPal requires PHP 8.4+.

### 1. Check Available PHP Versions

```bash
uberspace tools version list php
```

### 2. Set PHP 8.4 as Default

```bash
uberspace tools version use php 8.4
```

### 3. Verify PHP Version

```bash
php -v
# Output: PHP 8.4.x ...
```

### 4. Verify Required Extensions

```bash
php -m | grep -E 'mbstring|xml|ctype|iconv|intl|pdo_pgsql|sodium'
```

**Note:** All required extensions are pre-installed on Uberspace.

---

## PostgreSQL Setup

Uberspace provides PostgreSQL via `uberspace tools`.

### 1. Create PostgreSQL Database

```bash
# Create database
uberspace tools create postgresql

# Note the credentials shown (save them for .env)
# Example output:
# Database: <username>_secpal
# User: <username>_secpal
# Password: <random_password>
```

### 2. Connect to PostgreSQL

```bash
psql -U <username>_secpal -d <username>_secpal
```

### 3. Verify Connection

```sql
\conninfo
-- Output: You are connected to database "<username>_secpal" as user "<username>_secpal"

\q  -- Exit psql
```

---

## Application Deployment

### 1. Install Composer

Composer is pre-installed on Uberspace. Verify:

```bash
composer --version
# Output: Composer version 2.x.x
```

### 2. Clone Repository to Web Directory

**Important:** Clone directly to `/var/www/virtual/$USER/` (NOT to `/home`) because Apache cannot access `/home` directories.

```bash
cd /var/www/virtual/$USER
git clone https://github.com/SecPal/api.git secpal-api
cd secpal-api
```

### 3. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 4. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

### 5. Edit `.env` for Uberspace

```bash
nano .env
```

**Key settings:**

```env
# Application
APP_NAME=SecPal
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<username>.uber.space

# Database (use credentials from uberspace tools create postgresql)
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=<username>_secpal
DB_USERNAME=<username>_secpal
DB_PASSWORD=<password_from_uberspace_tools>

# KEK Path (absolute path in /var/www/virtual)
KEK_PATH=/var/www/virtual/<username>/secpal-api/storage/keys/kek.key

# Sanctum
SANCTUM_STATEFUL_DOMAINS=<username>.uber.space
SESSION_DOMAIN=.uber.space

# Mail (configure your SMTP provider)
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noreply@secpal.app
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@secpal.app
MAIL_FROM_NAME="${APP_NAME}"
```

Save and exit (`Ctrl+X`, then `Y`, then `Enter`).

### 6. Generate KEK (Key Encryption Key)

```bash
mkdir -p storage/keys
php -r "file_put_contents('storage/keys/kek.key', random_bytes(32));"
chmod 0600 storage/keys/kek.key
```

**⚠️ Backup KEK:** Download to secure offline storage:

```bash
# On your local machine
scp <username>@<username>.uber.space:/var/www/virtual/<username>/secpal-api/storage/keys/kek.key ~/secpal-kek-backup.key
```

### 8. Configure DocumentRoot Symlink

Uberspace serves from `/var/www/virtual/$USER/html`. Create a symlink to Laravel's public directory:

```bash
# Remove default html directory (if exists)
rm -rf /var/www/virtual/$USER/html

# Create symlink to Laravel public directory
ln -s /var/www/virtual/$USER/secpal-api/public /var/www/virtual/$USER/html
```

Verify symlink:

```bash
ls -la /var/www/virtual/$USER/html
# Output: html -> /var/www/virtual/<username>/secpal-api/public
```

### 9. Run Database Migrations

```bash
php artisan migrate --force
```

### 10. Seed Predefined Roles

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

### 11. Initialize Tenant Keys

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
```

### 12. Validate Setup

```bash
php artisan app:validate-setup
```

**Expected Output:**

```txt
SecPal Application Setup Validation
====================================

✅ Database Connection ... OK
✅ Tenant Key Exists ..... OK
✅ KEK File Readable ..... OK
✅ KEK Unwraps DEK ....... OK
✅ Storage Writable ....... OK

All checks passed! Application is ready.
```

---

## Testing the Deployment

### Access Health Check Endpoint

```bash
curl https://<username>.uber.space/api/health
```

**Expected Response:**

```json
{
  "status": "healthy",
  "timestamp": "2025-01-01T12:00:00.000000Z"
}
```

### Test Application from Browser

1. Visit `https://<username>.uber.space/api/health`
2. Should see JSON health status (no Laravel error page)

---

## Deployment Updates

### Updating the Application

When deploying a new version:

```bash
cd /var/www/virtual/$USER/secpal-api
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## Troubleshooting

### Application Not Accessible

**Problem:** Website shows 500 error or blank page.

**Solution:**

```bash
# Verify symlink exists
ls -la /var/www/virtual/$USER/html
# Should show: html -> /var/www/virtual/<username>/secpal-api/public

# Check Laravel logs
tail -f /var/www/virtual/$USER/secpal-api/storage/logs/laravel.log

# Check Apache error logs
tail -f ~/logs/error_log

# Common issues:
# 1. Symlink broken or missing
# 2. Database connection failed (.env credentials)
# 3. KEK file not readable
# 4. Missing storage permissions
```

### Health Check Returns 503

**Problem:** `curl https://<username>.uber.space/api/health` returns 503.

**Solutions:**

1. **Missing tenant keys:**

   ```bash
   cd /var/www/virtual/$USER/secpal-api
   php artisan tenant:setup
   ```

2. **Database connection failed:**

   ```bash
   # Test database connection
   php artisan tinker
   >>> DB::connection()->getPdo();
   ```

3. **KEK file not readable:**

   ```bash
   chmod 0600 /var/www/virtual/$USER/secpal-api/storage/keys/kek.key
   ```

### Database Migration Fails

**Problem:** `php artisan migrate` fails with permission errors.

**Solution:**

```bash
# Verify database credentials in .env
cat .env | grep DB_

# Test connection manually
psql -U <username>_secpal -d <username>_secpal -c '\conninfo'
```

---

## Uberspace-Specific Commands

### Useful Uberspace Commands

```bash
# List all domains
uberspace web domain list

# View PostgreSQL credentials
cat ~/.postgresql_password

# Check Apache error logs
tail -f ~/logs/error_log

# Check disk usage
quota -s
```

---

## Backup and Security

### Backup KEK File

**Critical:** Always backup the KEK file offline:

```bash
# From your local machine
scp <username>@<username>.uber.space:/var/www/virtual/<username>/secpal-api/storage/keys/kek.key ~/secpal-kek-backup-$(date +%Y%m%d).key
```

### Database Backups

```bash
# Create PostgreSQL backup
pg_dump <username>_secpal > ~/secpal-backup-$(date +%Y%m%d).sql

# Download backup to local machine
scp <username>@<username>.uber.space:~/secpal-backup-*.sql ~/backups/
```

### File Permissions Check

```bash
# Verify correct permissions
ls -la /var/www/virtual/$USER/secpal-api/storage/keys/kek.key
# Should show: -rw------- (600)

ls -la /var/www/virtual/$USER/secpal-api/.env
# Should show: -rw------- (600)
```

---

## Security Notes

**Uberspace-Specific:**

- ✅ Uberspace automatically manages SSL certificates via Let's Encrypt
- ✅ All traffic is encrypted (HTTPS)
- ✅ KEK file is protected (only readable by your user)
- ⚠️ Never share your Uberspace password or PostgreSQL credentials

**Always verify:**

- ✅ `.env` has correct database credentials
- ✅ `APP_DEBUG=false` in production
- ✅ Health checks return 200 OK

---

## Resources

- [Uberspace Manual](https://manual.uberspace.de/)
- [Uberspace PHP Guide](https://manual.uberspace.de/lang-php/)
- [Uberspace PostgreSQL Guide](https://manual.uberspace.de/database-postgresql/)
- [Deployment Guides](https://github.com/SecPal/api/tree/main/docs)
- [SecPal Deployment Checklist](./deployment-checklist.md)

---

**Last Updated:** November 27, 2025
**For Support:** <https://github.com/SecPal/api/issues>
