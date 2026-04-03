<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Authentication API Documentation

## Overview

SecPal API uses **Laravel Sanctum** for authentication, supporting two modes:

1. **httpOnly Cookie Authentication (SPA Mode)** - Recommended for browser-based SPAs (React PWA)
2. **Bearer Token Authentication** - For API clients (mobile apps, third-party integrations)

## Official Endpoint Responsibilities

- `POST /v1/auth/login` is the browser-only session login endpoint for first-party SPA requests that completed the Sanctum CSRF/cookie flow.
- `POST /v1/auth/token` is the stateless Bearer-token login endpoint for Android, native, CLI, and other API clients.
- `POST /v1/auth/logout` is the canonical logout endpoint for both authenticated modes.
- `GET /v1/me` is the canonical self-service endpoint for the authenticated caller.
- `POST /v1/auth/session/logout` remains available only as a deprecated compatibility alias for older SPA clients.
- `GET /v1/auth/me`, `GET /v1/user`, `GET /v1/user/profile`, and `GET /v1/profile` are intentionally unsupported and return `404 Not Found`.

## Runtime Decision Matrix

| Request shape                                                                                                | Expected endpoint                    | Success mode                                          | Wrong-context / failure semantics                       |
| ------------------------------------------------------------------------------------------------------------ | ------------------------------------ | ----------------------------------------------------- | ------------------------------------------------------- |
| First-party SPA request after `/sanctum/csrf-cookie`, with stateful Sanctum cookies and `Origin` / `Referer` | `POST /v1/auth/login`                | Session login via Laravel `web` guard                 | Invalid credentials remain `422 Unprocessable Entity`   |
| Stateless API / CLI / native request                                                                         | `POST /v1/auth/token`                | Personal access token minted for the requested device | Invalid credentials remain `422 Unprocessable Entity`   |
| Direct API-style call to `POST /v1/auth/login` without browser session context                               | None; caller used the wrong endpoint | Rejected before controller execution                  | `400 Bad Request` with guidance to use `/v1/auth/token` |

The `400` vs `422` distinction is intentional and should remain stable:

- `400 Bad Request` means the caller chose the wrong login surface.
- `422 Unprocessable Entity` means the caller reached the correct surface but supplied invalid data or credentials.

## Logout Semantics

`POST /v1/auth/logout` is protected by `auth:sanctum`, so the route accepts either a stateful SPA session or a Bearer token. The route is intentionally shared, but the side effect depends on the auth mode Sanctum actually resolved for the current request.

- If Sanctum authenticated the request via a personal access token, logout revokes only the current token.
- If Sanctum authenticated the request via the SPA session, logout invalidates the browser session and clears remember-me state.
- `POST /v1/auth/session/logout` delegates to the same session logout logic and exists only for backward compatibility.

### Mixed Requests

Laravel Sanctum checks for a valid first-party session context before it falls back to a Bearer token. Because of that, requests that accidentally carry both cookies and an `Authorization` header must follow the auth context Sanctum resolved, not the mere presence of the header.

This distinction is important for `POST /v1/auth/logout`:

- a browser-authenticated request with an accidental `Authorization` header must still log out the browser session
- an API-token-authenticated request must revoke only the current token
- raw header sniffing such as `bearerToken() !== null` is not a safe way to decide logout behavior

The current implementation uses the resolved `currentAccessToken()` state instead of the raw header so mixed requests do not silently drift into the wrong logout branch.

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
POST /v1/auth/login
Content-Type: application/json
X-XSRF-TOKEN: <token-from-cookie>

{
  "email": "user@example.com",
  "password": "password123"
}
```

`/v1/auth/login` is a first-party browser session endpoint. It is intended for requests that originate from the SecPal SPA, include the Sanctum session / CSRF cookie flow from `/sanctum/csrf-cookie`, and send the expected first-party browser headers such as `Origin` or `Referer`.

**Response:**

```http
HTTP/1.1 200 OK
Content-Type: application/json
Set-Cookie: laravel_session=<session>; path=/; HttpOnly; Secure; SameSite=lax

{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "user@example.com",
    "roles": ["Admin"],
    "permissions": ["*"],
    "hasOrganizationalScopes": true
  }
}
```

**Important:** For SPA mode, use `/v1/auth/login`. `/v1/auth/token` remains the official endpoint for Android, native, CLI, and other Bearer-token clients.

Direct JSON/API-style calls that do not establish the browser session context are rejected with `400 Bad Request` and a JSON message directing API clients to `/v1/auth/token`, instead of falling through to a server error.

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
  "email": "user@example.com",
  "roles": ["Admin"],
  "permissions": ["*"],
  "hasOrganizationalScopes": true
}
```

`GET /v1/me` is the official self-service root for the authenticated caller. Similar-looking aliases such as `/v1/auth/me`, `/v1/user`, `/v1/user/profile`, or `/v1/profile` are not defined.

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
  "message": "Logged out successfully"
}
```

> **Note:** `/v1/auth/logout` is the canonical logout endpoint for both SPA session auth and Bearer-token auth. `/v1/auth/session/logout` remains available only as a legacy compatibility alias for older SPA clients and should not be used by new clients.

For future maintenance, keep the canonical logout semantics aligned with the resolved auth context. A browser request that merely carries an extra `Authorization` header must not be treated as a token logout.

## Regression Hotspots

These areas are the most likely to re-mix session and token auth accidentally:

- `routes/api.php`: removing `EnsureBrowserSessionLoginContext` from `POST /v1/auth/login`, or moving the route to a broader auth surface
- `bootstrap/app.php`: changing the API middleware order around `EnsureFrontendRequestsAreStateful`, CSRF handling, or `RestoreSessionFromRememberToken`
- `AuthController::logout()`: branching on raw headers instead of the auth context Sanctum resolved
- CSRF configuration: exempting `POST /v1/auth/login` would weaken the browser/session boundary
- protected route middleware: replacing `auth:sanctum` with a narrower guard would break the deliberate shared self-service/logout surface

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

#### 400 - Wrong Login Context

If `/v1/auth/login` is called like a stateless API endpoint without the expected first-party browser/session context:

```http
HTTP/1.1 400 Bad Request
Content-Type: application/json

{
  "message": "This endpoint requires a browser session context. Use /v1/auth/token for API clients."
}
```

**Solution:** Browser SPAs must initialize Sanctum via `/sanctum/csrf-cookie` and continue with cookie-based session auth. Android, native, CLI, and other stateless clients must use `/v1/auth/token`.

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
  "email": "user@secpal.dev",
  "password": "password123",
  "device_name": "mobile-app"
}
```

`device_name` is optional. When it is omitted or sent as blank whitespace, the API falls back to `api-client`. Native clients should still send a meaningful device-specific value so issued tokens stay understandable during revocation and multi-device support.

**Response:**

```http
HTTP/1.1 201 Created
Content-Type: application/json

{
  "token": "1|abc123def456...",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "user@secpal.dev",
    "roles": ["Manager"],
    "permissions": ["employees.read"],
    "hasOrganizationalScopes": false
  }
}
```

Personal access tokens expire automatically after the configured server-side window. The default is `1440` minutes (24 hours) via `SANCTUM_TOKEN_EXPIRY_MINUTES`. Clients should treat explicit logout, token revocation, token expiry, and `401 Unauthorized` responses as signals that they need to re-authenticate. Credential changes (for example, password resets) do not automatically invalidate existing personal access tokens unless an endpoint explicitly revokes them, so clients MUST NOT assume tokens are revoked on credential change and MUST handle `401 Unauthorized` responses gracefully.

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
2. **Implement re-authentication logic** - tokens do not auto-expire today and are not automatically revoked on password reset or credential rotation by default; clients must recover cleanly after explicit revocation, logout, 401 responses, or future expiry/credential-revocation policy changes
3. **Handle 401 errors** gracefully - prompt for re-authentication
4. **Use device-specific names** - easier to manage multiple sessions

## Configuration

### Environment Variables

```env
# Stateful domains for SPA mode (comma-separated)
SANCTUM_STATEFUL_DOMAINS=app.secpal.dev

# Session configuration
SESSION_DRIVER=database
SESSION_DOMAIN=.secpal.dev
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
- [ ] `SESSION_DOMAIN` matches the active environment (for example `.secpal.dev`)
- [ ] HTTPS configured with valid SSL certificate

## Testing

### Manual Testing (cURL)

**Get CSRF token:**

```bash
curl -X GET http://api.secpal.dev/sanctum/csrf-cookie \
  -c cookies.txt -b cookies.txt -i
```

**Token login (API clients):**

```bash
curl -X POST http://api.secpal.dev/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password123"}'
```

**SPA login:** use `/v1/auth/login` only with the full Sanctum browser-session flow, including `/sanctum/csrf-cookie`, cookies, and first-party browser headers. For ad hoc CLI testing, prefer `POST /v1/auth/token`.

**Authenticated request:**

```bash
curl -X GET http://api.secpal.dev/v1/me \
  -b cookies.txt
```

### Automated Testing (Pest)

See test examples:

- `tests/Feature/Auth/SanctumCookieAuthTest.php` - httpOnly cookie tests
- `tests/Feature/Auth/CsrfProtectionTest.php` - CSRF validation tests
- `tests/Feature/AuthTest.php` - auth surface, Bearer token tests, and unsupported alias regression coverage

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
   await fetch("/v1/auth/login", {
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
