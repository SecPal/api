<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Production Deployment Checklist

Quick reference checklist for SecPal API production deployment.

**Full Guide:** See [deployment.md](./deployment.md) for detailed instructions.

---

## Pre-Deployment

### System Requirements

- [ ] Linux server (Ubuntu 22.04+ or Debian 12+)
- [ ] PHP 8.4+ installed
- [ ] PostgreSQL 15+ or 16+ installed
- [ ] Composer 2.x installed
- [ ] Required PHP extensions installed (`mbstring`, `xml`, `intl`, `pdo_pgsql`, `sodium`)
- [ ] SSH access with sudo privileges
- [ ] Domain name configured (optional for initial setup)

### Repository Setup

- [ ] Clone repository: `git clone https://github.com/SecPal/api.git`
- [ ] Install dependencies: `composer install --no-dev --optimize-autoloader`
- [ ] Copy environment file: `cp .env.example .env`
- [ ] Generate app key: `php artisan key:generate`

### Environment Configuration

- [ ] Edit `.env` with production values
- [ ] Set `APP_ENV=production`
- [ ] Set `APP_DEBUG=false`
- [ ] Configure `APP_URL` (e.g., `https://api.secpal.app`)
- [ ] Configure database credentials (`DB_*`)
- [ ] Configure mail settings (`MAIL_*`)
- [ ] Configure Sanctum domains (`SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`)

---

## Security Configuration

### KEK (Key Encryption Key) Generation

- [ ] Create keys directory: `mkdir -p storage/keys`
- [ ] Generate KEK: `php -r "file_put_contents('storage/keys/kek.key', random_bytes(32));"`
- [ ] Set KEK permissions: `chmod 0600 storage/keys/kek.key`
- [ ] Set KEK ownership: `chown www-data:www-data storage/keys/kek.key`
- [ ] Update `.env`: `KEK_PATH=storage/keys/kek.key` (or absolute path outside web root)
- [ ] **Backup KEK securely** (encrypted offline storage)

### File Permissions

- [ ] Set storage permissions: `sudo chmod -R 775 storage bootstrap/cache`
- [ ] Set storage ownership: `sudo chown -R www-data:www-data storage bootstrap/cache`
- [ ] Restrict `.env` permissions: `chmod 0600 .env`
- [ ] Set `.env` ownership: `chown www-data:www-data .env`

---

## Initial Setup

### Database Initialization

- [ ] Create PostgreSQL database: `CREATE DATABASE secpal_production;`
- [ ] Create database user: `CREATE USER secpal_user WITH ENCRYPTED PASSWORD '...';`
- [ ] Grant privileges: `GRANT ALL PRIVILEGES ON DATABASE secpal_production TO secpal_user;`
- [ ] Run migrations: `php artisan migrate --force`
- [ ] Verify migrations: `php artisan migrate:status`
- [ ] Seed predefined roles: `php artisan db:seed --class=RoleSeeder`

### Tenant Key Setup

- [ ] Run tenant setup: `php artisan tenant:setup`
- [ ] Verify output: "Tenant key setup complete!"
- [ ] Validate setup: `php artisan app:validate-setup`
- [ ] Verify output: "All checks passed!"

---

## Post-Deployment Verification

### Health Checks

- [ ] Test liveness probe: `curl http://localhost/health/live`
  - Expected: 200 OK `{"status":"ok"}`
- [ ] Test readiness probe: `curl http://localhost/health/ready`
  - Expected: 200 OK `{"status":"ready","checks":{"database":"ok","tenant_key":"ok"}}`

### Functional Tests

- [ ] Test login works:

  ```bash
  curl -X POST http://localhost/api/v1/auth/token \
    -H "Content-Type: application/json" \
    -d '{"email":"admin@secpal.app","password":"..."}'
  ```

  - Expected: 200 OK with access token

- [ ] Test secrets API:

  ```bash
  curl http://localhost/api/v1/secrets \
    -H "Authorization: Bearer YOUR_TOKEN"
  ```

  - Expected: 200 OK with empty array

### Logging & Monitoring

- [ ] Check Laravel logs: `tail -f storage/logs/laravel.log`
  - Expected: No critical errors
- [ ] Check web server logs: `sudo tail -f /var/log/nginx/error.log`
  - Expected: No errors related to SecPal
- [ ] Verify no plaintext keys in logs (security audit)

---

## Common Issues

### Health Check Returns 503

**Problem:** `/health/ready` returns 503.

**Solutions:**

1. Missing tenant keys: `php artisan tenant:setup`
2. Database connection failed: Check `.env` credentials
3. KEK file not readable: `chmod 0600 /path/to/kek.key`

### Secrets API Returns 503

**Problem:** `/api/v1/secrets` returns 503 "No tenant keys available".

**Solution:** `php artisan tenant:setup`

### KEK Permissions Warning

**Problem:** Setup validation warns about insecure KEK permissions.

**Solution:** `chmod 0600 /path/to/kek.key && chown www-data:www-data /path/to/kek.key`

---

## Security Reminders

**NEVER commit:**

- ❌ `.env` (contains secrets)
- ❌ `storage/keys/kek.key` (master encryption key)
- ❌ `storage/logs/*.log` (may contain sensitive data)

**Always verify:**

- ✅ KEK file has `0600` permissions
- ✅ `.env` has `0600` permissions
- ✅ `APP_DEBUG=false` in production
- ✅ Health checks return 200 OK

---

## Resources

- [Full Deployment Guide](./deployment.md)
- [Uberspace Deployment](./deployment-uberspace.md)
- [RBAC Setup](./guides/role-management.md)
- [Health Check API](./api/health-checks.md)

---

**Last Updated:** November 27, 2025
**For Support:** <https://github.com/SecPal/api/issues>
