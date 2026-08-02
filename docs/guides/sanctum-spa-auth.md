<!-- SPDX-FileCopyrightText: 2025-2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# Sanctum SPA Authentication with httpOnly Cookies

## Overview

SecPal uses Laravel Sanctum's SPA authentication with httpOnly cookies for secure, XSS-protected authentication. This guide covers configuration, usage, and troubleshooting.

## Why httpOnly Cookies?

### Security Benefits

**Traditional Token-Based (localStorage):**

- ❌ Vulnerable to XSS attacks
- ❌ Token accessible via JavaScript
- ❌ Manual token management required
- ❌ No built-in expiration enforcement

**httpOnly Cookie-Based:**

- ✅ XSS-protected (JavaScript cannot access)
- ✅ Browser handles tokens automatically
- ✅ CSRF protection via Sanctum
- ✅ Server-side expiration control
- ✅ Secure + SameSite flags

## Architecture

### Authentication Flow

```text
┌─────────┐                  ┌─────────┐                  ┌─────────┐
│ Frontend│                  │  Backend│                  │ Database│
└────┬────┘                  └────┬────┘                  └────┬────┘
     │                            │                            │
     │ GET /sanctum/csrf-cookie   │                            │
     │───────────────────────────>│                            │
     │                            │                            │
     │ Set-Cookie: XSRF-TOKEN=... │                            │
     │<───────────────────────────│                            │
     │ Set-Cookie: secpal-session │                            │
     │                            │                            │
     │ POST /v1/auth/login        │                            │
     │ Header: X-XSRF-TOKEN       │                            │
     │ Body: {email, password}    │                            │
     │───────────────────────────>│                            │
     │                            │ Validate credentials       │
     │                            │───────────────────────────>│
     │                            │<───────────────────────────│
     │                            │                            │
     │ Set-Cookie: secpal-session │                            │
     │ Body: {user: {...}}        │                            │
     │<───────────────────────────│                            │
     │                            │                            │
    │ GET /v1/me                 │                            │
     │ Cookie: secpal-session     │                            │
     │ Header: X-XSRF-TOKEN       │                            │
     │───────────────────────────>│                            │
     │                            │ Validate session + CSRF    │
     │                            │───────────────────────────>│
     │                            │<───────────────────────────│
    │ Body: {user: {...}}        │                            │
     │<───────────────────────────│                            │
```

### Cookie Types

**1. Session Cookie (`secpal-session`)**

- **Purpose:** Stores encrypted session identifier
- **httpOnly:** `true` (JavaScript cannot access)
- **Secure:** `true` in production (HTTPS only)
- **SameSite:** `lax` (CSRF protection)
- **Lifetime:** 120 minutes (configurable)

**2. CSRF Token Cookie (`XSRF-TOKEN`)**

- **Purpose:** Anti-CSRF token for state-changing requests
- **httpOnly:** `false` (JavaScript must read it)
- **Secure:** `true` in production
- **SameSite:** `lax`
- **Lifetime:** Session-based

## Configuration

### Backend Configuration

#### 1. Sanctum Stateful Domains

```php
// config/sanctum.php
'stateful' => explode(',', env(
    'SANCTUM_STATEFUL_DOMAINS',
  'app.secpal.dev'
)),
```

**Environment Variables:**

```env
# .env
SANCTUM_STATEFUL_DOMAINS=app.secpal.dev
```

#### 2. CORS Configuration

```php
// config/cors.php
'paths' => ['api/*', 'v1/*', 'health', 'health/*', 'sanctum/csrf-cookie'],
'supports_credentials' => true, // CRITICAL!
'allowed_origins' => [],
'allowed_origins_patterns' => array_map(
  static fn (string $origin): string => '#^'.preg_quote(trim($origin), '#').'$#',
  explode(',', env('CORS_ALLOWED_ORIGINS')),
),
'allowed_headers' => [
    'Content-Type',
    'Authorization',
    'X-Requested-With',
    'X-XSRF-TOKEN' // CSRF token header
],
```

**Environment Variables:**

```env
# .env
CORS_ALLOWED_ORIGINS=https://app.secpal.dev
CORS_SUPPORTS_CREDENTIALS=true
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Requested-With,X-XSRF-TOKEN
```

#### 3. Session Configuration

```php
// config/session.php
'driver' => env('SESSION_DRIVER', 'database'), // default: database, use 'cookie' for simpler setups
'lifetime' => 120,
'http_only' => true,
'secure' => env('SESSION_SECURE_COOKIE'),
'same_site' => 'lax',
```

**Environment Variables:**

```env
# .env
SESSION_DRIVER=database  # or 'cookie' for file-based sessions
SESSION_LIFETIME=120
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_SECURE_COOKIE=false  # true in production (HTTPS)
SESSION_DOMAIN=null  # Set to .yourdomain.com for subdomains
```

### Frontend Configuration

#### Environment Variables

```env
# .env
VITE_API_URL=http://localhost:8000
```

#### API Service Example

```typescript
// src/utils/csrf.ts
export function getCsrfToken(): string | null {
  const cookies = document.cookie.split(";");
  const xsrfCookie = cookies.find((c) => c.trim().startsWith("XSRF-TOKEN="));

  if (!xsrfCookie) return null;

  const token = xsrfCookie.split("=")[1];
  return decodeURIComponent(token);
}

export async function fetchCsrfCookie(): Promise<void> {
  const apiUrl = import.meta.env.VITE_API_URL;
  await fetch(`${apiUrl}/sanctum/csrf-cookie`, {
    credentials: "include",
  });
}

// src/services/authApi.ts
import { getCsrfToken, fetchCsrfCookie } from "../utils/csrf";

const API_URL = import.meta.env.VITE_API_URL;

interface LoginCredentials {
  email: string;
  password: string;
}

export async function login(credentials: LoginCredentials) {
  // Step 1: Get CSRF token
  await fetchCsrfCookie();

  // Step 2: Login with credentials
  const response = await fetch(`${API_URL}/v1/auth/login`, {
    method: "POST",
    credentials: "include", // Send/receive cookies
    headers: {
      "Content-Type": "application/json",
      "X-XSRF-TOKEN": getCsrfToken() || "",
    },
    body: JSON.stringify(credentials),
  });

  const data = await response.json();
  return data; // Returns {user}; SPA auth is handled via the session cookie
}

// All subsequent requests
export async function fetchCurrentUser() {
  const response = await fetch(`${API_URL}/v1/me`, {
    credentials: "include",
    headers: {
      "X-XSRF-TOKEN": getCsrfToken() || "",
    },
  });

  return response.json();
}
```

## API Endpoints

### CSRF Token Endpoint

```http
GET /sanctum/csrf-cookie HTTP/1.1
Host: api.secpal.dev

HTTP/1.1 204 No Content
Set-Cookie: XSRF-TOKEN=eyJpdiI6...; SameSite=lax
Set-Cookie: secpal-session=eyJpdiI6...; HttpOnly; SameSite=lax
```

### Login Endpoint

```http
POST /v1/auth/login HTTP/1.1
Host: api.secpal.dev
Content-Type: application/json
X-XSRF-TOKEN: eyJpdiI6...
Cookie: secpal-session=...

{
  "email": "user@example.com",
  "password": "password123"
}

HTTP/1.1 200 OK
Set-Cookie: secpal-session=...; HttpOnly; SameSite=lax
Content-Type: application/json

{
  "user": {
    "id": "uuid",
    "email": "user@example.com",
    "name": "John Doe",
    "roles": ["Manager"],
    "permissions": ["employees.read", "shifts.read", "work_instructions.read"],
    "hasOrganizationalScopes": true
  }
}
```

### Authenticated Request

```http
GET /v1/me HTTP/1.1
Host: api.secpal.dev
Cookie: secpal-session=...
X-XSRF-TOKEN: eyJpdiI6...

HTTP/1.1 200 OK
Content-Type: application/json

{
  "id": "uuid",
  "email": "user@example.com",
  "name": "John Doe",
  "roles": ["Manager"],
  "permissions": ["employees.read", "shifts.read", "work_instructions.read"],
  "hasOrganizationalScopes": true
}
```

### Logout Endpoint

```http
POST /v1/auth/logout HTTP/1.1
Host: api.secpal.dev
Cookie: secpal-session=...
X-XSRF-TOKEN: eyJpdiI6...

HTTP/1.1 200 OK
Set-Cookie: secpal-session=; Expires=Thu, 01-Jan-1970 00:00:01 GMT
```

## Testing

### Feature Tests

```php
// tests/Feature/Auth/SanctumCookieAuthTest.php
test('complete authentication flow with httpOnly cookies', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    // Step 1: Get CSRF token
    $response = $this->get('/sanctum/csrf-cookie');
    $response->assertNoContent();

    $csrfToken = $response->getCookie('XSRF-TOKEN')->getValue();

    // Step 2: Login
    $response = $this->withHeaders([
        'X-XSRF-TOKEN' => $csrfToken,
    ])->postJson('/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertCreated();

    $sessionCookie = $response->getCookie('secpal-session');
    expect($sessionCookie->isHttpOnly())->toBeTrue();

    // Step 3: Access protected endpoint
    $response = $this->withHeaders([
        'X-XSRF-TOKEN' => $csrfToken,
    ])->withCookie('secpal-session', $sessionCookie->getValue())
        ->getJson('/v1/me');

    $response->assertOk();
});
```

### Manual Testing

```bash
# 1. Get CSRF token
curl -c cookies.txt -X GET http://api.secpal.dev/sanctum/csrf-cookie -i

# 2. Extract XSRF-TOKEN from cookies.txt
CSRF_TOKEN=$(grep XSRF-TOKEN cookies.txt | sed 's/.*XSRF-TOKEN\s*//' | cut -f1)

# 3. Login with credentials
curl -b cookies.txt -c cookies.txt \
  -X POST http://api.secpal.dev/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: $CSRF_TOKEN" \
  -d '{"email":"test@example.com","password":"password"}' \
  -i

# 4. Make authenticated request
curl -b cookies.txt \
  -X GET http://api.secpal.dev/v1/me \
  -H "X-XSRF-TOKEN: $CSRF_TOKEN" \
  -i
```

## Troubleshooting

### Issue: Cookies Not Being Set

**Symptoms:**

- Login succeeds but no session cookie
- CSRF token missing
- Subsequent requests return 401

**Solutions:**

1. **Check SANCTUM_STATEFUL_DOMAINS**

   ```bash
   # Backend .env must include frontend domain
   SANCTUM_STATEFUL_DOMAINS=app.secpal.dev
   ```

1. **Verify CORS Configuration**

   ```php
   // config/cors.php
   'supports_credentials' => true, // MUST be true!
   'allowed_origins' => [],
   'allowed_origins_patterns' => ['#^https://app\\.secpal\\.dev$#'], // Exact-match origin
   ```

1. **Inspect Browser Cookies**
   - Open DevTools → Application → Cookies
   - Verify `secpal-session` and `XSRF-TOKEN` exist
   - Check cookie domain/path

### Issue: CSRF Token Mismatch (419)

**Symptoms:**

- POST/PUT/DELETE requests fail with 419 status

**Solutions:**

1. **Fetch CSRF Token Before Login**

   ```typescript
   await fetchCsrfCookie(); // MUST call first!
   await login(credentials);
   ```

2. **Include CSRF Token in Header**

   ```typescript
   headers: {
     'X-XSRF-TOKEN': getCsrfToken() // Required!
   }
   ```

3. **Clear Cookies and Retry**

   ```bash
   # Browser DevTools → Application → Cookies → Clear All
   # Or programmatically:
   document.cookie.split(";").forEach(c => {
     document.cookie = c.trim().split("=")[0] + "=;expires=" + new Date(0).toUTCString();
   });
   ```

### Issue: Session Not Persisting

**Symptoms:**

- User logged out after page refresh
- Session expires too quickly

**Solutions:**

1. **Check Session Configuration**

   ```env
   SESSION_DRIVER=cookie  # Not 'array' in production!
   SESSION_LIFETIME=120   # Minutes
   SESSION_DOMAIN=null    # Or .yourdomain.com for subdomains
   ```

2. **Verify SameSite Attribute**

   ```env
   SESSION_SAME_SITE=lax  # 'strict' breaks cross-site navigation
   ```

3. **HTTPS in Production**

   ```env
   SESSION_SECURE_COOKIE=true  # HTTPS only!
   APP_URL=https://api.secpal.dev
   ```

### Issue: CORS Errors

**Symptoms:**

- Requests blocked with CORS error
- "Access-Control-Allow-Credentials" missing

**Solutions:**

1. **Allow Credentials in CORS**

   ```php
   // config/cors.php
   'supports_credentials' => true,
   ```

1. **Explicit Allowed Origins**

   ```php
   // DON'T use '*' with credentials!
   'allowed_origins' => [],
   'allowed_origins_patterns' => ['#^https://app\\.secpal\\.dev$#'],
   ```

1. **Frontend Must Send Credentials**

   ```typescript
   fetch(url, {
     credentials: "include", // REQUIRED!
   });
   ```

## Production Deployment

### Checklist

- [ ] **HTTPS Enabled**

  - `SESSION_SECURE_COOKIE=true`
  - `APP_URL=https://api.secpal.dev`

- [ ] **Domain Configuration**

  - `SESSION_DOMAIN=.secpal.dev` (for subdomains)
  - `SANCTUM_STATEFUL_DOMAINS=app.secpal.dev`

- [ ] **CORS Configuration**

  - `CORS_ALLOWED_ORIGINS=https://app.secpal.dev`
  - `CORS_SUPPORTS_CREDENTIALS=true`

- [ ] **Session Security**

  - `SESSION_DRIVER=database` (or redis for scale)
  - `SESSION_LIFETIME=120` (or as required)
  - `SESSION_ENCRYPT=true` (encrypt the stored session payload)

- [ ] **Sanctum Configuration**
  - Verify `sanctum.stateful` includes production domain
  - Test CSRF protection works

### Nginx Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name api.secpal.dev;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    # Let Laravel handle CORS to keep origin checks aligned with config/cors.php.
    # Do not add static Access-Control-Allow-Origin headers here.

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Security Best Practices

### ✅ DO

- Use HTTPS in production (`SESSION_SECURE_COOKIE=true`)
- Set `SESSION_SAME_SITE=lax` for CSRF protection
- Explicitly whitelist frontend domains in `SANCTUM_STATEFUL_DOMAINS`
- Always send `X-XSRF-TOKEN` header for state-changing requests
- Validate CSRF tokens on backend
- Use short session lifetimes (2 hours recommended)
- Implement session refresh for long-lived sessions

### ❌ DON'T

- Use `allowed_origins = ['*']` with `supports_credentials = true`
- Store sensitive data in cookies (use session storage)
- Disable CSRF protection in production
- Use `SESSION_SAME_SITE=none` unless absolutely necessary
- Allow HTTP in production (use HTTPS!)
- Set `SESSION_HTTP_ONLY=false` for session cookie
- Expose session identifiers in logs

## References

- [Laravel Sanctum Documentation](https://laravel.com/docs/sanctum#spa-authentication)
- [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- [MDN: HTTP Cookies](https://developer.mozilla.org/en-US/docs/Web/HTTP/Cookies)
- [MDN: Set-Cookie](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie)
- [Laravel CORS Documentation](https://laravel.com/docs/routing#cors)

## Related Documentation

- [Role Management Guide](./role-management.md)
- [Temporal Roles Guide](./temporal-roles.md)
