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
- [Domain Configuration](#domain-configuration)
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

### 2. Clone Repository

```bash
cd ~
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

# KEK Path (absolute path)
KEK_PATH=/home/<username>/secpal-api/storage/keys/kek.key

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
scp <username>@<username>.uber.space:~/secpal-api/storage/keys/kek.key ~/secpal-kek-backup.key
```

### 7. Run Database Migrations

```bash
php artisan migrate --force
```

### 8. Seed Predefined Roles

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

### 9. Initialize Tenant Keys

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

### 10. Validate Setup

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

## Domain Configuration

### 1. Configure Web Backend

Uberspace uses Apache and PHP-FPM to serve web applications securely. Point the web backend to your Laravel application's `public/` directory:

```bash
# Link web backend to Laravel public directory
uberspace web backend set / --apache /home/<username>/secpal-api/public
```

This ensures requests are handled by Apache and PHP-FPM, providing production-grade security, performance, and reliability.

> **⚠️ Important:** Do not use `php artisan serve` for production deployments. The built-in development server lacks security and performance features required for production use. Always use Apache/PHP-FPM on Uberspace.

For more details, see:

- [Uberspace: Web Backends](https://manual.uberspace.de/web-backends/)
- [Laravel Deployment Best Practices](https://laravel.com/docs/deployment)

### 2. Verify Web Backend Configuration

```bash
# Check web backend status
uberspace web backend list
```

**Expected Output:**

```txt
/ apache /home/<username>/secpal-api/public
```

### 3. Configure Queue Worker (Optional)

If your application uses queues, set up a queue worker with systemd:

```bash
nano ~/.config/systemd/user/secpal-queue.service
```

**Service file content:**

```ini
[Unit]
Description=SecPal Queue Worker
After=network.target

[Service]
Type=simple
WorkingDirectory=/home/<username>/secpal-api
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=10

[Install]
WantedBy=default.target
```

Enable and start the queue worker:

```bash
systemctl --user daemon-reload
systemctl --user enable secpal-queue.service
systemctl --user start secpal-queue.service
systemctl --user status secpal-queue.service
```

### 4. Test API Endpoint

```bash
curl https://<username>.uber.space/health/live
```

**Expected Response:**

```json
{
  "status": "ok",
  "timestamp": "2025-11-27T12:00:00Z"
}
```

### 5. Configure Custom Domain (Optional)

If you have a custom domain (e.g., `api.secpal.app`):

```bash
# Add domain to Uberspace
uberspace web domain add api.secpal.app

# Configure web backend for custom domain
uberspace web backend set api.secpal.app / --apache /home/<username>/secpal-api/public
```

Update `.env`:

```env
APP_URL=https://api.secpal.app
SANCTUM_STATEFUL_DOMAINS=api.secpal.app,app.secpal.app
SESSION_DOMAIN=.secpal.app
```

Clear Laravel caches:

```bash
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
# Check web backend configuration
uberspace web backend list

# Verify public directory exists
ls -la ~/secpal-api/public

# Check Laravel logs
tail -f ~/secpal-api/storage/logs/laravel.log

# Check Apache error logs
tail -f ~/logs/error_log

# Common issues:
# 1. Wrong public directory path in web backend
# 2. Database connection failed (.env credentials)
# 3. KEK file not readable
# 4. Missing storage permissions
```

### Health Check Returns 503

**Problem:** `curl https://<username>.uber.space/health/ready` returns 503.

**Solutions:**

1. **Missing tenant keys:**

   ```bash
   cd ~/secpal-api
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
   chmod 0600 ~/secpal-api/storage/keys/kek.key
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
# List all web backends
uberspace web backend list

# List all domains
uberspace web domain list

# View PostgreSQL credentials
cat ~/.postgresql_password

# Check service logs
journalctl --user -u secpal-api.service -f  # Follow logs
```

### Queue Worker Management (if configured)

```bash
# Start queue worker
systemctl --user start secpal-queue.service

# Stop queue worker
systemctl --user stop secpal-queue.service

# Restart queue worker
systemctl --user restart secpal-queue.service

# View status
systemctl --user status secpal-queue.service

# View logs
journalctl --user -u secpal-queue.service -n 100
```

---

## Deployment Updates

When deploying code updates:

```bash
cd ~/secpal-api

# Pull latest changes
git pull origin main

# Update dependencies
composer install --no-dev --optimize-autoloader

# Run migrations (if any)
php artisan migrate --force

# Clear caches
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Restart queue worker (if configured)
systemctl --user restart secpal-queue.service
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
