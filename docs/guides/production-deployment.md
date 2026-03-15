<!-- SPDX-FileCopyrightText: 2025 SecPal Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Production Deployment Guide

## Overview

This guide covers deploying SecPal API to production with Sanctum authentication supporting both:

- **httpOnly Cookie-based** (Web SPA, PWA in browser)
- **Bearer Token-based** (Native mobile apps, CLI, scripts)

## Pre-Deployment Checklist

### Security

- [ ] **HTTPS/TLS enabled** on production domain
  - Valid SSL certificate (Let's Encrypt, purchased cert)
  - HTTP automatically redirects to HTTPS
  - Test with: `curl -I https://api.secpal.app`

- [ ] **Environment Variables Secured**
  - `APP_KEY` generated: `php artisan key:generate`
  - `APP_DEBUG=false` in production
  - `.env` file has restrictive permissions: `chmod 600 .env`
  - `.env` excluded from version control

- [ ] **Database Secured**
  - Strong database password
  - Database not exposed to public internet
  - Backup strategy in place

- [ ] **Session Security**

  ```env
  SESSION_SECURE_COOKIE=true     # HTTPS only
  SESSION_HTTP_ONLY=true         # XSS protection
  SESSION_SAME_SITE=lax          # CSRF protection
  SESSION_DRIVER=database        # or redis for scale
  ```

- [ ] **CORS Configuration**

  ```env
  CORS_ALLOWED_ORIGINS=https://app.secpal.app  # Explicit origins, NO wildcards with credentials
  CORS_SUPPORTS_CREDENTIALS=true
  ```

- [ ] **Sanctum Stateful Domains**

  ```env
  SANCTUM_STATEFUL_DOMAINS=app.secpal.app,admin.secpal.app
  SESSION_DOMAIN=.secpal.app  # For subdomain cookie sharing
  ```

### Application

- [ ] **Migrations Run**

  ```bash
  php artisan migrate --force
  ```

- [ ] **Caches Optimized**

  ```bash
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php artisan event:cache
  ```

- [ ] **Storage Linked**

  ```bash
  php artisan storage:link
  ```

- [ ] **Permissions Set**

  ```bash
  chmod -R 775 storage bootstrap/cache
  chown -R www-data:www-data storage bootstrap/cache
  ```

- [ ] **Queue Workers Running** (if using queues)

  ```bash
  php artisan queue:work --daemon --tries=3
  ```

### Testing

- [ ] **Static Analysis Passes**

  ```bash
  vendor/bin/phpstan analyze --level=max
  ```

- [ ] **Code Style Compliant**

  ```bash
  vendor/bin/pint --test
  ```

- [ ] **All Tests Pass**

  ```bash
  php artisan test
  ```

- [ ] **Health Check Endpoint**
  - Test: `curl https://api.secpal.app/health`
  - Should return 200 OK

## Nginx Configuration

### Basic Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name api.secpal.app;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/api.secpal.app/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.secpal.app/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    # Document Root
    root /var/www/secpal-api/public;
    index index.php;

    # Logging
    access_log /var/log/nginx/secpal-api-access.log;
    error_log /var/log/nginx/secpal-api-error.log;

    # Security Headers (if not handled by Laravel middleware)
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # CORS Headers (already handled by Laravel, but can add here)
    # Only add if NOT using Laravel's HandleCors middleware
    # add_header 'Access-Control-Allow-Origin' 'https://app.secpal.app' always;
    # add_header 'Access-Control-Allow-Credentials' 'true' always;
    # add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS' always;
    # add_header 'Access-Control-Allow-Headers' 'Content-Type,Authorization,X-XSRF-TOKEN' always;

    # Laravel Front Controller
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM Configuration
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;  # Adjust PHP version
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Proxy headers for Laravel
        fastcgi_param HTTP_X_REAL_IP $remote_addr;
        fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Deny access to sensitive files
    location ~ /(\.env|\.git|composer\.json|composer\.lock|phpunit\.xml) {
        deny all;
    }
}

# HTTP to HTTPS Redirect
server {
    listen 80;
    server_name api.secpal.app;
    return 301 https://$host$request_uri;
}
```

### Nginx with Rate Limiting

```nginx
# Add to http block in nginx.conf
http {
    # Rate limiting zones
    limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;    # 5 login attempts per minute
    limit_req_zone $binary_remote_addr zone=api:10m rate=60r/m;     # 60 API requests per minute
    limit_req_status 429;

    server {
        # ... SSL config ...

        # Rate limit login endpoint
        location ~ ^/v1/auth/(token|login) {
            limit_req zone=login burst=3 nodelay;
            try_files $uri $uri/ /index.php?$query_string;
        }

        # Rate limit general API
        location ~ ^/v1/ {
            limit_req zone=api burst=10 nodelay;
            try_files $uri $uri/ /index.php?$query_string;
        }
    }
}
```

## Apache Configuration

### .htaccess (Laravel Default)

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Force HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]

    # Laravel Front Controller
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

# Security Headers
<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

### VirtualHost Configuration

```apache
<VirtualHost *:443>
    ServerName api.secpal.app
    DocumentRoot /var/www/secpal-api/public

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/api.secpal.app/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/api.secpal.app/privkey.pem
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite HIGH:!aNULL:!MD5

    <Directory /var/www/secpal-api/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/secpal-api-error.log
    CustomLog ${APACHE_LOG_DIR}/secpal-api-access.log combined
</VirtualHost>

# HTTP to HTTPS Redirect
<VirtualHost *:80>
    ServerName api.secpal.app
    Redirect permanent / https://api.secpal.app/
</VirtualHost>
```

## Environment Configuration

### Production .env Template

```env
# Application
APP_NAME=SecPal
APP_ENV=production
APP_KEY=base64:GENERATED_KEY_HERE
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://api.secpal.app
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=secpal_production
DB_USERNAME=secpal_user
DB_PASSWORD=STRONG_RANDOM_PASSWORD

# Session & Cache
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_COOKIE=secpal-session
SESSION_PATH=/
SESSION_DOMAIN=.secpal.app
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=redis

# Sanctum
SANCTUM_STATEFUL_DOMAINS=app.secpal.app,admin.secpal.app

# CORS
CORS_ALLOWED_ORIGINS=https://app.secpal.app,https://admin.secpal.app
CORS_SUPPORTS_CREDENTIALS=true
CORS_ALLOWED_METHODS=GET,POST,PUT,PATCH,DELETE,OPTIONS
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Requested-With,X-XSRF-TOKEN

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@secpal.app"
MAIL_FROM_NAME="${APP_NAME}"

# Logging
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
```

## Client Configuration

### Web SPA / PWA (httpOnly Cookies)

```env
# frontend/.env.production
VITE_API_URL=https://api.secpal.app
```

**Authentication Flow:**

```typescript
// 1. Get CSRF token
await fetch(`${apiUrl}/sanctum/csrf-cookie`, {
  credentials: "include",
});

// 2. Login
await fetch(`${apiUrl}/v1/auth/token`, {
  method: "POST",
  credentials: "include",
  headers: {
    "Content-Type": "application/json",
    "X-XSRF-TOKEN": getCsrfToken(),
  },
  body: JSON.stringify({ email, password }),
});

// 3. Authenticated requests
await fetch(`${apiUrl}/v1/me`, {
  credentials: "include",
  headers: {
    "X-XSRF-TOKEN": getCsrfToken(),
  },
});
```

### Native Mobile App (Bearer Token)

```kotlin
// Android (Kotlin)
val apiUrl = "https://api.secpal.app"

// 1. Login
val response = httpClient.post("$apiUrl/v1/auth/token") {
    contentType(ContentType.Application.Json)
    setBody(LoginRequest(email, password))
}
val token = response.body<LoginResponse>().token

// 2. Store token securely
EncryptedSharedPreferences.create(/* ... */).edit {
    putString("auth_token", token)
}

// 3. Authenticated requests
httpClient.get("$apiUrl/v1/me") {
    bearerAuth(token)
}
```

## Monitoring & Maintenance

### Health Checks

Create health check endpoint for monitoring:

```php
// routes/web.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toIso8601String(),
        'database' => DB::connection()->getPdo() ? 'connected' : 'disconnected',
    ]);
});
```

Monitor with:

```bash
# Uptime monitoring
curl https://api.secpal.app/health

# Expected response:
# {"status":"healthy","timestamp":"2025-11-25T20:00:00+00:00","database":"connected"}
```

### Log Rotation

```bash
# /etc/logrotate.d/laravel
/var/www/secpal-api/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        /usr/bin/systemctl reload php8.4-fpm > /dev/null
    endscript
}
```

### Automated Backups

```bash
#!/bin/bash
# /usr/local/bin/secpal-backup.sh

# Database backup
mysqldump -u secpal_user -p'PASSWORD' secpal_production | gzip > /backups/secpal-db-$(date +%Y%m%d).sql.gz

# Application backup (excluding vendor/)
tar -czf /backups/secpal-app-$(date +%Y%m%d).tar.gz \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='storage/logs' \
    /var/www/secpal-api/

# Keep only last 7 days
find /backups -name "secpal-*" -mtime +7 -delete
```

Add to crontab:

```cron
0 2 * * * /usr/local/bin/secpal-backup.sh
```

## Troubleshooting Production Issues

### CORS Errors

**Symptom:** Browser blocks requests with CORS error

**Solution:**

```bash
# 1. Check CORS configuration
php artisan config:show cors

# 2. Verify SANCTUM_STATEFUL_DOMAINS includes frontend domain
grep SANCTUM_STATEFUL_DOMAINS .env

# 3. Test CORS headers
curl -I -H "Origin: https://app.secpal.app" https://api.secpal.app/v1/me

# Should see:
# Access-Control-Allow-Origin: https://app.secpal.app
# Access-Control-Allow-Credentials: true
```

### Session/Cookie Issues

**Symptom:** Login succeeds but subsequent requests return 401

**Solution:**

```bash
# 1. Check session configuration
php artisan config:show session

# 2. Verify SESSION_DOMAIN
# For app.secpal.app accessing api.secpal.app:
SESSION_DOMAIN=.secpal.app  # Note the leading dot!

# 3. Ensure HTTPS in production
SESSION_SECURE_COOKIE=true

# 4. Clear caches
php artisan config:clear
php artisan cache:clear
```

### Performance Issues

**Symptom:** Slow response times

**Solution:**

```bash
# 1. Enable caching
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 2. Optimize autoloader
composer install --optimize-autoloader --no-dev

# 3. Use Redis for sessions/cache
SESSION_DRIVER=redis
CACHE_STORE=redis

# 4. Enable OPcache in php.ini
opcache.enable=1
opcache.memory_consumption=256
```

## Security Incident Response

### Suspected Token Compromise

```bash
# Revoke all tokens for a user
php artisan tinker
>>> User::find($userId)->tokens()->delete();

# Or revoke specific token
>>> PersonalAccessToken::findToken($token)->delete();
```

### Force All Users to Re-authenticate

```bash
# Clear all sessions
php artisan session:flush

# Or truncate sessions table
DB::table('sessions')->truncate();
```

### Update APP_KEY (breaks existing sessions)

```bash
# Generate new key (WARNING: invalidates all sessions/encrypted data)
php artisan key:generate --force

# Clear caches
php artisan config:cache
```

## Rollback Procedure

```bash
# 1. Stop services
sudo systemctl stop nginx php8.4-fpm

# 2. Restore database
mysql -u secpal_user -p secpal_production < /backups/secpal-db-20251124.sql

# 3. Restore application
cd /var/www
sudo rm -rf secpal-api
sudo tar -xzf /backups/secpal-app-20251124.tar.gz

# 4. Restore .env from backup
sudo cp /backups/.env.20251124 /var/www/secpal-api/.env

# 5. Clear caches
cd /var/www/secpal-api
php artisan config:clear
php artisan cache:clear

# 6. Restart services
sudo systemctl start php8.4-fpm nginx
```

## References

- [Laravel Deployment Documentation](https://laravel.com/docs/deployment)
- [Sanctum SPA Authentication](./sanctum-spa-auth.md)
- [Security Best Practices](https://laravel.com/docs/security)
- [Nginx Optimization](https://www.nginx.com/blog/tuning-nginx/)
