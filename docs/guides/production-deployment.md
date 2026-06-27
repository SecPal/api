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
  - Test with: `curl -I https://api.secpal.dev`

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
  CORS_ALLOWED_ORIGINS=https://app.secpal.dev  # Explicit origins, NO wildcards with credentials
  CORS_SUPPORTS_CREDENTIALS=true
  ```

- [ ] **Sanctum Stateful Domains**

  ```env
  SANCTUM_STATEFUL_DOMAINS=app.secpal.dev
  SESSION_DOMAIN=.secpal.dev  # For subdomain cookie sharing
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
  - Test: `curl https://api.secpal.dev/health`
  - Should return 200 OK

## Nginx Configuration

### Basic Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name api.secpal.dev;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/api.secpal.dev/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.secpal.dev/privkey.pem;
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
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "0" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; img-src 'self'; style-src 'unsafe-inline'; script-src 'none'; object-src 'none'" always;
    add_header Permissions-Policy "accelerometer=(), autoplay=(), camera=(), clipboard-read=(), clipboard-write=(), display-capture=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()" always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header Cross-Origin-Opener-Policy "same-origin" always;
    add_header Cross-Origin-Resource-Policy "same-site" always;
    add_header Cross-Origin-Embedder-Policy "require-corp" always;
    add_header Origin-Agent-Cluster "?1" always;
    add_header X-Permitted-Cross-Domain-Policies "none" always;

    # CORS is already handled by Laravel. Do not mirror it here with static
    # Access-Control-Allow-Origin headers, or disallowed origins may appear
    # half-authorized at the edge.

    # The API host keeps the same cross-origin isolation header classes as the
    # PWA, but `Cross-Origin-Resource-Policy` remains `same-site` instead of
    # `same-origin` so the first-party SPA on app.secpal.dev can continue to
    # fetch api.secpal.dev responses without weakening the general baseline.

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
    server_name api.secpal.dev;
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
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "0"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; img-src 'self'; style-src 'unsafe-inline'; script-src 'none'; object-src 'none'"
    Header always set Permissions-Policy "accelerometer=(), autoplay=(), camera=(), clipboard-read=(), clipboard-write=(), display-capture=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()"
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"
    Header always set Cross-Origin-Opener-Policy "same-origin"
    Header always set Cross-Origin-Resource-Policy "same-site"
    Header always set Cross-Origin-Embedder-Policy "require-corp"
    Header always set Origin-Agent-Cluster "?1"
    Header always set X-Permitted-Cross-Domain-Policies "none"
</IfModule>
```

### API vs. App Header Policy

- `api.secpal.dev` should keep the same header classes as the app host: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Content-Security-Policy`, `Permissions-Policy`, `Strict-Transport-Security`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, `Cross-Origin-Embedder-Policy`, `Origin-Agent-Cluster`, and `X-Permitted-Cross-Domain-Policies`.
- The API host uses a stricter document policy than the PWA: `default-src 'none'` plus only the allowances needed for branded HTML error pages (`img-src 'self'`, `style-src 'unsafe-inline'`).
- `Cross-Origin-Resource-Policy` should be `same-site` on the API so the first-party SPA on `app.secpal.dev` remains compatible while unrelated sites stay outside the baseline.
- `Cross-Origin-Embedder-Policy`, `Origin-Agent-Cluster`, and `X-Permitted-Cross-Domain-Policies` are present on the API for consistency, but the API still does not mirror the app's broader asset policy because JSON responses and branded error pages need a tighter `default-src 'none'` baseline.

### VirtualHost Configuration

```apache
<VirtualHost *:443>
    ServerName api.secpal.dev
    DocumentRoot /var/www/secpal-api/public

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/api.secpal.dev/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/api.secpal.dev/privkey.pem
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
    ServerName api.secpal.dev
    Redirect permanent / https://api.secpal.dev/
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
APP_URL=https://api.secpal.dev
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

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
ANDROID_PUSH_FCM_PROJECT_ID=customer-owned-push
ANDROID_PUSH_FCM_CLIENT_EMAIL=firebase-adminsdk-abc123@customer-owned-push.iam.gserviceaccount.com
ANDROID_PUSH_FCM_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nREPLACE_WITH_DEPLOYMENT_SECRET\n-----END PRIVATE KEY-----\n"
ANDROID_PUSH_FCM_TOKEN_URI=https://oauth2.googleapis.com/token
ANDROID_PUSH_FCM_API_BASE_URL=https://fcm.googleapis.com
ANDROID_PUSH_FCM_CONNECT_TIMEOUT=5
ANDROID_PUSH_FCM_TIMEOUT=10
WEB_PUSH_VAPID_SUBJECT=mailto:notifications@secpal.dev
WEB_PUSH_VAPID_PRIVATE_KEY=REPLACE_WITH_BASE64URL_VAPID_PRIVATE_KEY
WEB_PUSH_DELIVERY_TTL=300
WEB_PUSH_DELIVERY_URGENCY=normal
WEB_PUSH_DELIVERY_CONNECT_TIMEOUT=5
WEB_PUSH_DELIVERY_TIMEOUT=20
BOOTSTRAP_RETRYABLE=true
BOOTSTRAP_RETRY_AFTER_SECONDS=60

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
SESSION_ENCRYPT=true
SESSION_COOKIE=secpal-session
SESSION_PATH=/
SESSION_DOMAIN=.secpal.dev
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
SANCTUM_STATEFUL_DOMAINS=app.secpal.dev

# CORS
CORS_ALLOWED_ORIGINS=https://app.secpal.dev
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

`GET /v1/bootstrap` derives the canonical `api_base_url` from `APP_URL` and appends `/v1`. Keep `APP_URL` pointed at the externally reachable API origin, and set the `BOOTSTRAP_MINIMUM_SUPPORTED_APP_*` values before exposing the public bootstrap endpoint on a customer-hosted deployment.

When `BOOTSTRAP_ANDROID_PUSH_ENABLED=true`, the bootstrap response advertises authenticated `android_fcm` support through exhaustive `features.notification_channels` flags and returns canonical `notification_channels.android_fcm` runtime metadata with the deployment-defined `metadata_revision` plus the public Android runtime metadata needed by the SDK. When `BOOTSTRAP_WEB_PUSH_ENABLED=true`, the same response advertises authenticated `web_push` support and returns canonical `notification_channels.web_push` runtime metadata with the deployment-defined `metadata_revision` plus the public VAPID key required by browser Push API clients. Missing `BOOTSTRAP_ANDROID_PUSH_*` or `BOOTSTRAP_WEB_PUSH_*` public values fail closed with `500 BOOTSTRAP_STATE_INVALID` using channel-aware `notification_channels.*` field paths.

Authenticated Android and browser clients register against the selected customer-hosted backend via the canonical `PUT /v1/me/notification-installations/{installationId}` surface and revoke via `DELETE /v1/me/notification-installations/{installationId}`. Clients must echo the current per-channel `notification_channels.<channel>.metadata_revision` in `runtime.metadata_revision`; stale registrations are rejected with `409 NOTIFICATION_RUNTIME_STATE_INVALID`, and deployments with a disabled channel reject registration with `409 NOTIFICATION_CHANNEL_UNSUPPORTED` for that channel.

Customer-owned Android push delivery uses the backend-only `ANDROID_PUSH_FCM_*` secrets above and sends directly to FCM HTTP v1 from the customer-hosted API. Those credentials never appear in `GET /v1/bootstrap`, and missing or invalid values fail closed during delivery instead of falling back to any SecPal-operated routing path. `ANDROID_PUSH_FCM_TOKEN_URI` and `ANDROID_PUSH_FCM_API_BASE_URL` must remain the canonical Google endpoints shown in the example environment; the backend rejects alternate hosts instead of proxying OAuth or message delivery through a non-Google endpoint.

The queueable delivery primitive is `App\Jobs\DeliverAndroidPushMessage` on the default queue. Keep the default queue worker running, and expect delivery outcomes such as `UNREGISTERED`, `SENDER_ID_MISMATCH`, or token-specific invalid-argument responses to delete the stored device registration so clients must re-register.

Customer-owned browser Web Push delivery uses the backend-only `WEB_PUSH_VAPID_*` and `WEB_PUSH_DELIVERY_*` settings together with the existing public `BOOTSTRAP_WEB_PUSH_PUBLIC_VAPID_KEY`. The audited `minishlink/web-push` library signs and encrypts delivery directly from the customer-hosted API, `App\Services\QueueWebPushDeliveryService::dispatchToRegistrations()` deduplicates targeted browser registration ids per tenant before enqueueing `App\Jobs\DeliverWebPushMessage`, and stale browser subscriptions (`subscription_expires_at`, corrupted stored material, or push-service `404` / `410`) are deleted so the next authenticated browser session must re-register. Private VAPID credentials plus full subscription endpoints and keys never appear in `GET /v1/bootstrap` or API resources.

For every deployment that enables Android FCM bootstrap, also run the Google Cloud console audit in [`docs/guides/firebase-push-bootstrap-audit.md`](firebase-push-bootstrap-audit.md). The codebase can prove bootstrap minimization and server-side secret separation, but it cannot prove the live Firebase / Google Cloud key allowlist, unrelated API exposure, quota posture, billing alerts, or current App Check applicability.

## Client Configuration

### Web SPA / PWA (httpOnly Cookies)

```env
# frontend/.env.production
VITE_API_URL=https://api.secpal.dev
```

**Authentication Flow:**

```typescript
// 1. Get CSRF token
await fetch(`${apiUrl}/sanctum/csrf-cookie`, {
  credentials: "include",
});

// 2. Login
await fetch(`${apiUrl}/v1/auth/login`, {
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
val apiUrl = "https://api.secpal.dev"

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
curl https://api.secpal.dev/health

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
curl -I -H "Origin: https://app.secpal.dev" https://api.secpal.dev/v1/me

# Should see:
# Access-Control-Allow-Origin: https://app.secpal.dev
# Access-Control-Allow-Credentials: true
```

### Session/Cookie Issues

**Symptom:** Login succeeds but subsequent requests return 401

**Solution:**

```bash
# 1. Check session configuration
php artisan config:show session

# 2. Verify SESSION_DOMAIN
# For app.secpal.dev accessing api.secpal.dev:
SESSION_DOMAIN=.secpal.dev  # Note the leading dot!

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
