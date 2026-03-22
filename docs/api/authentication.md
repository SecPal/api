<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Authentication API Documentation

## Overview

SecPal API uses **Laravel Sanctum** for authentication, supporting two modes:

1. **httpOnly Cookie Authentication (SPA Mode)** - Recommended for browser-based SPAs (React PWA)
2. **Bearer Token Authentication** - For API clients (mobile apps, third-party integrations)

## httpOnly Cookie Authentication (SPA Mode)

### Security Benefits

- **XSS Protection**: Cookies with `httpOnly` flag cannot be accessed by JavaScript
- **CSRF Protection**: Laravel's CSRF token validation protects against cross-site request forgery
- **Secure by Default**: Cookies use `SameSite=lax` and `Secure` flag in production

### Authentication Flow

#### Step 1: Get CSRF Token

Before making any authenticated requests, fetch a CSRF token:

```http
GET /sanctum/csrf-cookie
```

**Response:**

```http
HTTP/1.1 204 No Content
Set-Cookie: XSRF-TOKEN=<token>; path=/; SameSite=lax
Set-Cookie: laravel_session=<session>; path=/; HttpOnly; SameSite=lax
```

The `XSRF-TOKEN` cookie is readable by JavaScript and must be sent in the `X-XSRF-TOKEN` header for state-changing requests (POST, PUT, PATCH, DELETE).

#### Step 2: Login

```http
POST /v1/auth/token
Content-Type: application/json
X-XSRF-TOKEN: <token-from-cookie>

{
  "email": "user@example.com",
  "password": "password123",
  "device_name": "web-browser" // Optional
}
```

**Response:**

```http
HTTP/1.1 201 Created
Content-Type: application/json
Set-Cookie: laravel_session=<session>; path=/; HttpOnly; Secure; SameSite=lax

{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "user@example.com"
  }
}
```

**Important:** For SPA mode, ignore the `token` field. Authentication is handled via the `laravel_session` httpOnly cookie automatically sent by the browser.

#### Step 3: Authenticated Requests

All subsequent requests automatically include the session cookie:

```http
GET /v1/me
Cookie: laravel_session=<session>
```

**Response:**

```http
HTTP/1.1 200 OK
Content-Type: application/json

{
  "id": 1,
  "name": "John Doe",
  "email": "user@example.com"
}
```

#### Step 4: Logout

```http
POST /v1/auth/logout
X-XSRF-TOKEN: <token-from-cookie>
Cookie: laravel_session=<session>
```

**Response (Bearer Token Mode):**

```http
HTTP/1.1 200 OK
Content-Type: application/json

{
  "message": "Token revoked successfully."
}
```

> **Note:** When using httpOnly cookie authentication, the logout endpoint works but the message is designed for Bearer token mode. The session is still properly invalidated.

### CSRF Token Handling

For all state-changing requests (POST, PUT, PATCH, DELETE), include the CSRF token:

1. **Read** the `XSRF-TOKEN` cookie value
2. **Send** it in the `X-XSRF-TOKEN` request header

**Example (JavaScript):**

```javascript
// Read CSRF token from cookie
function getCsrfToken() {
  const cookies = document.cookie.split(";");
  const xsrfCookie = cookies.find((c) => c.trim().startsWith("XSRF-TOKEN="));
  return xsrfCookie ? decodeURIComponent(xsrfCookie.split("=")[1]) : null;
}

// Make authenticated request
fetch("/v1/organizational-units", {
  method: "POST",
  credentials: "include", // Send cookies
  headers: {
    "Content-Type": "application/json",
    "X-XSRF-TOKEN": getCsrfToken(),
  },
  body: JSON.stringify({ name: "Operations", type: "department" }),
});
```

### Error Handling

#### 419 - CSRF Token Mismatch

If CSRF token is expired or invalid:

```http
HTTP/1.1 419 Page Expired
Content-Type: application/json

{
  "message": "CSRF token mismatch."
}
```

**Solution:** Re-fetch CSRF token from `/sanctum/csrf-cookie` and retry the request.

#### 401 - Unauthenticated

If session cookie is missing or expired:

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{
  "message": "Unauthenticated."
}
```

**Solution:** Redirect user to login page.

## Bearer Token Authentication (API Clients)

### Use Cases

- Mobile applications
- CLI tools
- Third-party integrations
- Non-browser API clients

### Authentication Flow

#### Step 1: Obtain Token

```http
POST /v1/auth/token
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123",
  "device_name": "mobile-app"
}
```

**Response:**

```http
HTTP/1.1 201 Created
Content-Type: application/json

{
  "token": "1|abc123def456...",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "user@example.com"
  }
}
```

**Store the token securely:**

- ✅ Mobile: Keychain (iOS), KeyStore (Android)
- ✅ CLI: Encrypted config file
- ❌ Never store in localStorage (XSS vulnerable)

#### Step 2: Authenticated Requests

Include token in `Authorization` header:

```http
GET /v1/me
Authorization: Bearer 1|abc123def456...
```

#### Step 3: Logout

Revoke current token:

```http
POST /v1/auth/logout
Authorization: Bearer 1|abc123def456...
```

Revoke all tokens (logout from all devices):

```http
POST /v1/auth/logout-all
Authorization: Bearer 1|abc123def456...
```

## Security Recommendations

### For SPA Developers

1. **Always use `credentials: 'include'`** in fetch/axios calls
2. **Never store tokens in localStorage** - rely on httpOnly cookies
3. **Handle 419 errors** by refreshing CSRF token and retrying
4. **Use HTTPS in production** - cookies require `Secure` flag

### For API Client Developers

1. **Store tokens securely** - use platform-specific secure storage
2. **Implement token refresh logic** - re-authenticate when token expires
3. **Handle 401 errors** gracefully - prompt for re-authentication
4. **Use device-specific names** - easier to manage multiple sessions

## Configuration

### Environment Variables

```env
# Stateful domains for SPA mode (comma-separated)
SANCTUM_STATEFUL_DOMAINS=app.secpal.dev

# Session configuration
SESSION_DRIVER=database
SESSION_DOMAIN=.secpal.dev # Use .secpal.app in production
SESSION_SECURE_COOKIE=true # HTTPS only
SESSION_LIFETIME=120 # minutes

# CORS configuration
CORS_ALLOWED_ORIGINS=https://app.secpal.dev
CORS_SUPPORTS_CREDENTIALS=true
```

### Production Checklist

- [ ] `SESSION_SECURE_COOKIE=true` (HTTPS only)
- [ ] `SANCTUM_STATEFUL_DOMAINS` includes `app.secpal.dev`
- [ ] `CORS_ALLOWED_ORIGINS` includes the active frontend domain
- [ ] `SESSION_DOMAIN` matches the active environment (for example `.secpal.dev` or `.secpal.app`)
- [ ] HTTPS configured with valid SSL certificate

## Testing

### Manual Testing (cURL)

**Get CSRF token:**

```bash
curl -X GET http://api.secpal.dev/sanctum/csrf-cookie \
  -c cookies.txt -b cookies.txt -i
```

**Login:**

```bash
curl -X POST http://api.secpal.dev/v1/auth/token \
  -c cookies.txt -b cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: $(grep XSRF-TOKEN cookies.txt | awk '{print $7}')" \
  -d '{"email":"test@example.com","password":"password123"}'
```

**Authenticated request:**

```bash
curl -X GET http://api.secpal.dev/v1/me \
  -b cookies.txt
```

### Automated Testing (Pest)

See test examples:

- `tests/Feature/Auth/SanctumCookieAuthTest.php` - httpOnly cookie tests
- `tests/Feature/Auth/CsrfProtectionTest.php` - CSRF validation tests
- `tests/Feature/AuthTest.php` - Bearer token tests

## Migration Guide

### From localStorage to httpOnly Cookies

If migrating from localStorage-based authentication:

1. **Remove token storage:**

   ```typescript
   // ❌ Old
   localStorage.setItem("auth_token", token);

   // ✅ New
   // No client-side storage needed
   ```

2. **Update fetch calls:**

   ```typescript
   // ❌ Old
   fetch("/v1/me", {
     headers: {
       Authorization: `Bearer ${localStorage.getItem("auth_token")}`,
     },
   });

   // ✅ New
   fetch("/v1/me", {
     credentials: "include", // Cookies sent automatically
   });
   ```

3. **Add CSRF token handling:**

   ```typescript
   // Fetch CSRF token before login
   await fetch("/sanctum/csrf-cookie", { credentials: "include" });

   // Then login
   await fetch("/v1/auth/token", {
     method: "POST",
     credentials: "include",
     headers: {
       "Content-Type": "application/json",
       "X-XSRF-TOKEN": getCsrfToken(),
     },
     body: JSON.stringify(credentials),
   });
   ```

## Related Documentation

- [Laravel Sanctum Documentation](https://laravel.com/docs/sanctum)
- [OWASP: Token Storage Security](https://cheatsheetseries.owasp.org/cheatsheets/JSON_Web_Token_for_Java_Cheat_Sheet.html#token-storage-on-client-side)
- [MDN: Using HTTP Cookies](https://developer.mozilla.org/en-US/docs/Web/HTTP/Cookies)
