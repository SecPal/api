<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# First-party browser session integration

This guide is the procedural companion to the
[authentication boundaries](../api/authentication.md). It is for developers
connecting the first-party browser/PWA client to the API's current Laravel
Sanctum session flow.

The
[SecPal public OpenAPI contract](https://github.com/SecPal/contracts/blob/main/docs/openapi.yaml)
owns exact request bodies, response bodies, status codes, and authentication
declarations. This guide does not repeat them.

## Before integrating

Confirm these responsibilities with the appropriate owner:

- The API environment recognizes the browser origin as a Sanctum stateful
  domain and an allowed credentialed CORS origin.
- The browser client sends cookies on the CSRF bootstrap, login, protected API,
  and logout requests.
- State-changing session requests copy the readable `XSRF-TOKEN` cookie value
  into the `X-XSRF-TOKEN` header.
- The client uses the session login flow for browser authentication, not a
  personal access token stored in browser JavaScript storage.

The checked-in application sources for those inputs are `config/sanctum.php`,
`config/cors.php`, `config/session.php`, and `bootstrap/app.php`. Production
origin values, cookie routing, TLS, reverse proxies, and host topology belong to
[SecPal/deployment](https://github.com/SecPal/deployment) and
[SecPal/api#1453](https://github.com/SecPal/api/issues/1453).

## Integration sequence

### 1. Bootstrap CSRF state

Request `GET /sanctum/csrf-cookie` with browser credentials enabled. Laravel
sets the CSRF cookie and initializes the session cookie. The session identifier
cookie is `HttpOnly`; the CSRF cookie remains readable so the client can echo it
in the request header.

### 2. Start the browser login

Send `POST /v1/auth/login` with browser credentials enabled, the
`X-XSRF-TOKEN` header, and the first-party `Origin` or `Referer` context.

The endpoint rejects calls that Sanctum did not classify as stateful browser
requests. If primary credentials are valid and MFA is enabled, the response is a
pending challenge: no authenticated session exists until challenge verification
succeeds. Follow the exact continuation described by the OpenAPI contract.

### 3. Use the authenticated session

Send protected requests with browser credentials enabled. Use `GET /v1/me` to
load the authenticated user's current self-service and authorization context.
Include `X-XSRF-TOKEN` on every state-changing session request; read-only
requests rely on the session cookie but do not need that header.

Laravel may restore an expired database session from the remembered browser
credential where the current middleware permits it. The restored request still
passes through the normal Sanctum and authorization boundaries.

### 4. End the session

Send `POST /v1/auth/logout` with browser credentials and `X-XSRF-TOKEN`. This is
the canonical logout operation. In a resolved browser-session context it clears
remember-me state and invalidates the current session.

Do not select logout behavior from the presence of an `Authorization` header.
Sanctum can resolve a valid first-party session on a request that also carries a
bearer header, and the API follows that resolved context.

## Troubleshooting

### Login reports the wrong context

Confirm that the client first requested `/sanctum/csrf-cookie`, retained the
cookies, sent credentials on the login request, and used the configured
first-party origin. Stateless, CLI, and native clients must use the bearer-token
flow described in the primary authentication document.

### CSRF validation returns 419

Fetch `/sanctum/csrf-cookie` again and retry with the current decoded
`XSRF-TOKEN` value in `X-XSRF-TOKEN`. Also confirm that the request retained the
same browser cookie context.

### A protected request returns 401

Treat the browser session as absent or expired and return to the login flow.
Do not switch silently to a bearer token in browser storage.

### The browser blocks the credentialed request

Verify the effective API origin and browser origin against the deployed CORS and
Sanctum inputs. Public-edge headers, proxy behavior, TLS, and host configuration
must be diagnosed in the deployment-owned documentation rather than repaired by
adding proxy configuration here.

## Verification surfaces

The focused observable tests are:

- `tests/Feature/Auth/SanctumCookieAuthTest.php` for browser-session login,
  protected requests, mixed credentials, and logout;
- `tests/Feature/Auth/CsrfProtectionTest.php` for CSRF and cookie behavior;
- `tests/Feature/Auth/SanctumIntegrationTest.php` for stateful-origin and CORS
  integration; and
- `tests/Feature/Middleware/RestoreSessionFromRememberTokenTest.php` for
  remembered-session restoration.

See the primary authentication document for MFA, passkey, bearer-token,
self-service, authorization, and implementation ownership boundaries.
