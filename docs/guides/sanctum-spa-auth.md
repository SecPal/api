<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# First-party browser session integration

This guide covers the request sequence for the first-party browser client.
See the [authentication boundaries](../api/authentication.md) for bearer
clients, MFA, passkeys and sign-out semantics. Use the
[OpenAPI contract](https://github.com/SecPal/contracts/blob/main/docs/openapi.yaml)
for request and response details.

1. Use the origin admitted by the API's
   [Sanctum configuration](../../config/sanctum.php) and credentialed CORS
   configuration. Send browser credentials with the requests below.
2. Request `GET /sanctum/csrf-cookie`. Keep the browser's cookies and send the
   decoded `XSRF-TOKEN` value as `X-XSRF-TOKEN` on state-changing requests.
3. Send `POST /v1/auth/login` in the same browser context. Complete any pending
   MFA challenge before treating the session as authenticated.
4. Send cookies with protected requests. Use the current caller endpoint to
   load the authenticated context.
5. Send `POST /v1/auth/logout` with the same cookie and CSRF context to end
   the current session.

If sign-in reports the wrong context, check the first-party origin and whether
the request carried cookies. For a CSRF rejection, bootstrap the cookie again
in the same browser context and update the request header. On an unauthenticated
response, use the browser sign-in flow.

The [browser session tests](../../tests/Feature/Auth/SanctumCookieAuthTest.php)
and [CSRF tests](../../tests/Feature/Auth/CsrfProtectionTest.php) cover the
application behavior. See the [API production boundary](../deployment.md) and
[deployment contracts](https://github.com/SecPal/deployment) for production
origin, proxy and cookie configuration.
