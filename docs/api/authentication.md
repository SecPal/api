<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Authentication boundaries

The [SecPal OpenAPI contract](https://github.com/SecPal/contracts/blob/main/docs/openapi.yaml)
defines authentication routes, schemas and security requirements. This page
explains the authentication contexts implemented by the API.

## Browser session and bearer token

The first-party browser client signs in through Laravel's `web` guard and uses
a session cookie. Sanctum classifies requests from the configured first-party
client as stateful and applies session and CSRF middleware. Follow the
[browser integration guide](../guides/sanctum-spa-auth.md) for the request
sequence.

Native clients and other stateless API clients use Sanctum personal access
tokens. Protected [API routes](../../routes/api.php) use Sanctum authentication
and require the `api-access` ability for bearer tokens. Policies and scoped
queries apply resource authorization after authentication. The
[RBAC documentation](../rbac-architecture.md) covers access policy.

The [authentication controller](../../app/Http/Controllers/AuthController.php)
and [authentication tests](../../tests/Feature/AuthTest.php) describe the
current behavior. [ADR-014](https://github.com/SecPal/.github/blob/main/docs/adr/20260720-tenant-identity-access-model-adr014.md)
defines the accepted identity and membership model.
[SecPal/api#1345](https://github.com/SecPal/api/issues/1345) tracks that delivery.

## Multi-factor authentication and passkeys

When TOTP-based MFA is enabled, password sign-in returns a pending challenge.
Complete it with a TOTP or recovery code to establish the original browser
session or receive a bearer token. The OpenAPI contract describes the
continuation and self-service operations.

Passkeys use separate WebAuthn challenges for browser and token clients. A
verified browser challenge establishes a session. A verified token challenge
issues a bearer token. The public sign-in challenges use discoverable
credentials. The [API production boundary](../deployment.md#passkey-configuration)
describes the relying-party and origin inputs.

## Current identity and sign-out

Use `GET /v1/me` to read the authenticated caller's profile and current access
context. Resource authorization remains server-side.

`POST /v1/auth/logout` ends the credential that Sanctum resolved for the request.
A bearer request revokes the current token. A stateful browser request
invalidates its session and clears remember-me state. If a request carries
both credentials, a valid stateful session takes precedence.

The separate account-wide sign-out operation, `POST /v1/auth/logout-all`, revokes
the caller's tokens and database sessions and clears remember-me state.
See the [authentication tests](../../tests/Feature/AuthTest.php) for the
current session and token behavior.

[SecPal frontend](https://github.com/SecPal/frontend) and
[SecPal Android](https://github.com/SecPal/android) own their client
implementations. [SecPal deployment](https://github.com/SecPal/deployment)
owns the public edge and host configuration.
