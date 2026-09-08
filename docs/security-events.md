<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Application security-event contract

SecPal emits a privacy-minimized, one-way application security-event stream for
defensive log consumers. Schema version `1` is the only current version. The
machine-readable schema and synthetic parser fixtures are in
[`security-events/v1`](security-events/v1/).

The API remains authoritative for authentication, authorization, throttling,
MFA, password reset, and account protection. Event consumers do not return
decisions to the API and are never availability or readiness dependencies.

## Output and fields

Each accepted event is serialized as one JSON object on the local
`security_events` log channel. Laravel defers the local write until after the
HTTP response; collectors may consume that output independently. The default
channel writes `storage/logs/security-events-*.log` and performs no network
request. A local sink, cache, or collector failure cannot alter the application
decision or response.

The v1 object is closed: unknown top-level or metadata fields are invalid.

| Field             | Meaning                                                                                                                      |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `schema_version`  | Integer `1`. Consumers must reject every other version.                                                                      |
| `event_name`      | Stable semantic identifier from the table below.                                                                             |
| `occurred_at`     | UTC timestamp with millisecond precision, formatted as `YYYY-MM-DDTHH:mm:ss.sssZ`.                                           |
| `outcome`         | Closed value: `failure` or `denied`.                                                                                         |
| `reason`          | Event-specific closed reason from the table below.                                                                           |
| `source_ip`       | Canonical IPv4 or IPv6 address from Laravel's trusted-client address resolution.                                             |
| `correlation_id`  | Application-generated UUID shared by security events created during one request. Incoming request-ID headers are not copied. |
| `actor_reference` | Optional keyed pseudonym described below.                                                                                    |
| `metadata`        | Required object with only the event-specific keys and values in the schema. It has at most two properties.                   |

## Event semantics

| Event                           | Trigger                                                                                                                                                                           | Outcome / reason                         | Metadata                                                                    |
| ------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- | --------------------------------------------------------------------------- |
| `authentication.failed`         | The final primary password check rejects a validated login or token request. It intentionally does not claim credential-stuffing intent; consumers may correlate repeated events. | `failure` / `invalid_credentials`        | `authentication_method=password`; `login_context=session\|token`            |
| `authentication.mfa_failed`     | A pending login challenge reaches the final MFA-code check and the submitted TOTP or recovery code is invalid.                                                                    | `failure` / `invalid_mfa_code`           | `authentication_method=totp\|recovery_code`; `login_context=session\|token` |
| `authentication.passkey_failed` | A browser or token login challenge reaches WebAuthn verification and the assertion is invalid, malformed, or cannot be verified.                                                  | `failure` / `invalid_passkey_credential` | `authentication_method=passkey`; `login_context=session\|token`             |
| `authentication.token_rejected` | Sanctum rejects a request that presented a bearer token. Expired, revoked, unknown, and malformed tokens are deliberately not distinguished after lookup failure.                 | `denied` / `invalid_or_expired`          | `authentication_method=bearer_token`                                        |
| `authentication.rate_limited`   | The existing login limiter denies a request after counted credential failures. This reports the application control; it does not implement another lockout.                       | `denied` / `rate_limited`                | `authentication_method=password`; `login_context=session\|token`            |
| `password_reset.token_rejected` | Password-reset confirmation rejects an unknown, missing, expired, invalid, or concurrently consumed token. Those internal cases intentionally share one reason.                   | `denied` / `invalid_or_expired`          | `reset_phase=confirmation`                                                  |
| `password_reset.rate_limited`   | The existing password-reset limiter denies either a reset request or confirmation.                                                                                                | `denied` / `rate_limited`                | `reset_phase=request\|confirmation`                                         |

Invalid validation payloads that never reach an authoritative security decision
do not create an event. A single decision is integrated once, at its owning
controller, exception renderer, or limiter callback.

## Privacy boundary

V1 cannot carry arbitrary metadata. Its DTO and parser reject raw email,
username, password, password hash, bearer or API token, cookies, CSRF token,
authorization header, password-reset token, MFA code or secret, WebAuthn
challenge/assertion material, request bodies, query payloads, user-agent text,
and translated or exception messages because no schema field can represent
them.

`actor_reference` is present only where cross-request account-input correlation
is useful. It is derived from the normalized login/reset email using HMAC-SHA256
with the decoded Laravel `APP_KEY` and the domain separator
`secpal:security-event:actor:v1` plus a NUL byte. The serialized form is
`hmac-sha256:v1:<64 lowercase hex characters>`. Ordinary log consumers cannot
reverse it or compare it with a public unsalted identifier hash. It is not a
user ID, tenant authority, authentication credential, or authorization input.
Changing the account email or rotating `APP_KEY` starts a new correlation
identity. Events without an authoritative or useful account input, including a
rejected bearer token or invalid passkey assertion, omit the field.

Known and unknown submitted account identifiers follow the same derivation, so
field presence does not encode account existence. Public authentication and
password-reset responses remain unchanged and never expose event names,
reasons, correlation IDs, or actor references.

## Trusted client address

`source_ip` is created only from Laravel's `$request->ip()`. The framework
accepts `X-Forwarded-For` only when the immediate peer matches the
`TRUSTED_PROXIES` IP/CIDR allowlist in `config/trustedproxy.php`; the allowlist
is empty by default. An untrusted caller's `X-Forwarded-For`, `Forwarded`, or
`X-Real-IP` header therefore cannot replace the peer address. This preserves the
deployment-owned HAProxy boundary without implementing proxy networking here.

## Telemetry amplification bound

The event emitter keeps application security controls separate from telemetry
volume control. In each 60-second window it writes at most five events for one
`schema version + event name + source IP + actor reference` fingerprint and at
most 300 events application-wide. Limits are enforced through the application's
shared Laravel rate-limiter cache. Admission compares each cache backend's
atomic increment result with both limits and rolls back rejected admissions, so
one exhausted quota does not consume the other quota. The first events remain
available as a detection signal; excess repeats are suppressed until the window
expires. Suppression never changes login, MFA, token, reset, or authorization
behavior.

## Consumer and versioning rules

Consumers must validate each JSON object against
[`v1/schema.json`](security-events/v1/schema.json) before interpretation and
must reject malformed events, unknown fields, unknown names or reasons, and
unknown schema versions. Validators must enable draft 2020-12 `format`
assertions so the timestamp's calendar semantics are checked in addition to its
exact millisecond UTC pattern. Source-IP branches also carry explicit structural
patterns for validators that treat `format` as annotation-only.
[`v1/events.jsonl`](security-events/v1/events.jsonl) contains one synthetic
example per supported event and uses only documentation addresses and
non-secret placeholders.

The PHP enums and event definition are the producer's authoritative invariant;
the JSON Schema independently enforces the downstream trust boundary. Maintained
agreement tests require both surfaces and the fixtures to accept exactly the
same supported event-name set and event-specific shapes.

A breaking field, enum, or semantic change requires a new integer schema
version, a separate versioned schema/fixture directory, and a changelog migration
notice. An older consumer must never reinterpret a newer shape as v1. The
fixtures are a producer handoff only; CrowdSec, SIEM, firewall, and deployment
parser configuration remain outside this repository.
