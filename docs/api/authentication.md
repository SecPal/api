<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Authentication boundaries

This document is the API repository's entry point for current authentication
behavior and implementation boundaries. It explains how the shipped Laravel
application establishes and ends authenticated contexts; it is not a second HTTP
specification.

Exact paths, request and response schemas, status codes, and security
declarations are owned by the
[SecPal public OpenAPI contract](https://github.com/SecPal/contracts/blob/main/docs/openapi.yaml).
When this explanation and the public contract differ, verify shipped behavior in
the API source and tests, then correct the owning source instead of copying the
contract here.

## Authentication contexts

SecPal uses Laravel Sanctum for two distinct authentication contexts:

| Context                  | Entry point           | Credential after sign-in                | Intended caller                                                              |
| ------------------------ | --------------------- | --------------------------------------- | ---------------------------------------------------------------------------- |
| Stateful browser session | `POST /v1/auth/login` | Laravel session and remember-me cookies | First-party browser/PWA requests that completed Sanctum's stateful CSRF flow |
| Bearer token             | `POST /v1/auth/token` | Sanctum personal access token           | Native, CLI, integration, and other non-browser API clients                  |

Protected API routes use `auth:sanctum`. Bearer tokens must also carry the
`api-access` ability. Sanctum treats an authenticated first-party session as a
transient token, so the same protected route group can accept both contexts
without giving a browser a personal access token.

The Laravel `web` guard provides the session behind Sanctum's stateful browser
mode. The `sanctum` guard first checks that configured session context and falls
back to a bearer token when no stateful principal is resolved. Guards answer how
a principal is authenticated; policies, gates, permission middleware, and
tenant-scoped queries answer what that principal may do.

## Browser session authentication

A browser request is stateful only when Sanctum recognizes its origin or
referrer and runs the session and CSRF middleware. The browser first obtains the
CSRF cookies from `/sanctum/csrf-cookie`, then sends the readable `XSRF-TOKEN`
value as `X-XSRF-TOKEN` on state-changing requests while allowing the browser to
send cookies.

`POST /v1/auth/login` is limited to that first-party browser context. The
`EnsureBrowserSessionLoginContext` middleware rejects a stateless call before
the controller runs. A successful password or passkey browser sign-in uses the
`web` guard, rotates the session identifier, and establishes a remembered
session. The session cookie is configured as `HttpOnly`, which reduces direct
JavaScript access to its value; this is not a general claim that browser code or
the application is immune to cross-site scripting.

CSRF validation protects state-changing session requests. It is not the browser
credential itself, and bearer-token requests do not become session requests by
supplying a CSRF header.

For the integration sequence and bounded troubleshooting guidance, see
[First-party browser session integration](../guides/sanctum-spa-auth.md).

## Bearer-token authentication

Non-browser clients authenticate through `POST /v1/auth/token`. A successful
password or passkey token flow issues a Sanctum personal access token with the
`api-access` ability. Subsequent requests present that token in the
`Authorization` header.

Token expiry is enforced by the server-side Sanctum configuration. Client
repositories own their platform-specific credential storage and recovery
behavior; this API documentation does not define Android, browser, or operating
system storage internals. A bearer token and a browser session therefore share
protected API routes but not identical storage or trust properties.

## Multi-factor authentication

TOTP and one-time recovery codes participate in password sign-in as a pending
transition:

1. The session or token login endpoint validates the primary credentials.
2. If TOTP-based MFA is enabled, the API returns a short-lived challenge instead
   of establishing a session or issuing a token.
3. Verification completes the original context carried by that challenge:
   browser challenges establish a session and token challenges issue a personal
   access token.

Invalid verification consumes the pending login challenge. Exact challenge
payloads, verification methods, response variants, and error schemas belong to
the public OpenAPI contract.

Authenticated, email-verified callers can inspect their MFA status, prepare and
confirm TOTP enrollment, regenerate recovery codes, and disable MFA under the
`/v1/me/mfa` self-service boundary. Recovery codes are shown in plaintext only
when first generated or explicitly regenerated. These are current
implementation semantics, not a reusable MFA protocol specification.

## Passkeys

Passkey authentication uses WebAuthn challenges and keeps the browser and token
contexts separate:

- browser authentication challenges require the first-party stateful context
  and establish a browser session after verification;
- token authentication challenges require a device name and issue a bearer
  token after verification; and
- a challenge must be verified in the context in which it was created.

The public authentication challenges are discoverable: the API does not use an
email address or an advertised credential allowlist to select an account.
Successful passkey verification completes authentication directly and does not
start a separate TOTP challenge.

Passkey management is authenticated self-service under `/v1/me/passkeys`.
Registration and deletion require current-password step-up, and registration
uses a short-lived challenge. Adding or deleting a passkey does not silently
disable the current TOTP enrollment or rotate recovery codes. The OpenAPI
contract owns the WebAuthn request and response schemas; client repositories own
platform ceremony APIs and user experience.

## Self-service boundary

`GET /v1/me` is the canonical current self-service root for the authenticated
caller. It returns the current user representation and the authorization context
that the shipped clients consume. Other self-service operations, including MFA,
passkeys, and language preference, live below `/v1/me` and are defined exactly
in the public OpenAPI contract.

The returned roles, permissions, scopes, and current tenant association describe
the shipped implementation. They do not redefine the future identity and access
model accepted in
[ADR-014](https://github.com/SecPal/.github/blob/main/docs/adr/20260720-tenant-identity-access-model-adr014.md).
Implementation of that future model remains coordinated by
[SecPal/api#1345](https://github.com/SecPal/api/issues/1345).

## Canonical logout

`POST /v1/auth/logout` is the single canonical logout operation. Its effect
follows the authentication context Sanctum resolved for the request:

- a bearer-authenticated request revokes only the currently authenticated
  personal access token; and
- a stateful browser request logs out the `web` guard, invalidates the current
  session, regenerates its CSRF token, and clears the user's remember-me state.

The public contract declares the bearer alternative separately from the
stateful `SessionAuth` plus `CsrfToken` alternative.

Sanctum checks a valid first-party session before falling back to bearer-token
authentication. If a browser request accidentally carries both cookies and an
`Authorization` header, logout follows the resolved session context. The
controller therefore branches on `currentAccessToken()` rather than the mere
presence of an authorization header.

## Trust and responsibility boundaries

- Authentication establishes a principal and its current session or token
  context. Authorization follows successful authentication and is documented
  separately in the [RBAC architecture](../rbac-architecture.md).
- Tenant authority is derived by the current server implementation, never from
  an untrusted route, header, query, or payload value. The future
  membership-based model in ADR-014 is not documented here as shipped behavior.
- Session cookie, CORS, and Sanctum stateful-origin settings are application
  inputs. Production public edge, reverse proxy, TLS termination, host secrets,
  and network topology belong to
  [SecPal/deployment](https://github.com/SecPal/deployment) and
  [SecPal/api#1453](https://github.com/SecPal/api/issues/1453).
- Exact public HTTP behavior belongs to SecPal/contracts. Client-side browser
  and native implementation details belong to the frontend and Android
  repositories.

## Implementation and evidence map

The current shipped boundary is implemented and evidenced primarily in:

- `routes/api.php`;
- `app/Http/Controllers/AuthController.php`;
- `app/Http/Middleware/EnsureBrowserSessionLoginContext.php`;
- `app/Http/Middleware/RestoreSessionFromRememberToken.php`;
- `bootstrap/app.php`, `config/auth.php`, `config/sanctum.php`, and
  `config/session.php`;
- `tests/Feature/AuthTest.php` and the tests under `tests/Feature/Auth/`;
- `tests/Feature/Middleware/RestoreSessionFromRememberTokenTest.php`; and
- the accepted SecPal/contracts OpenAPI document linked above.

These surfaces, rather than historical migration prose, own current behavior.

## Related documentation

- [First-party browser session integration](../guides/sanctum-spa-auth.md)
- [Laravel Sanctum documentation](https://laravel.com/docs/sanctum)
- [Laravel authentication documentation](https://laravel.com/docs/authentication)
- [SecPal frontend](https://github.com/SecPal/frontend)
- [SecPal Android](https://github.com/SecPal/android)
