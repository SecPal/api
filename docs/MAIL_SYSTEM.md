<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Local email development

## Mailpit

Run Mailpit in the local development environment with SMTP on
`127.0.0.1:1025` and the web UI on `127.0.0.1:8025`. Bind both listeners to
loopback and keep forwarding and relaying disabled for test messages.
[Mailpit configuration](https://mailpit.axllent.org/docs/configuration/)

Use the local SMTP configuration from [`.env.example`](../.env.example).
The defaults use the `smtp` mailer for both `MAIL_MAILER` and
`GLOBAL_IDENTITY_MAILER`. For a private preview, use the approved confined
SMTP sink for that environment.

Trigger the mail flow using synthetic account and message data. For work
dispatched through a queue, use the local worker described in the queue guide.
Inspect the recipient, template and link in the Mailpit UI at
<http://127.0.0.1:8025>. On a remote development machine, use a local SSH
forward to the loopback UI. [Local queue workers](QUEUE_WORKERS.md#local-development-and-diagnosis)

## Delivery and tests

The employee onboarding invitation is sent synchronously by its service.
Password resets, active email verification and pending email changes dispatch
a Global Identity operation after commit. The queued job carries the operation
identifier. The worker resolves the recipient and verifier at delivery time.
[Onboarding invitation service](../app/Services/EmployeeOnboardingInvitationService.php) ·
[Identity delivery job](../app/Jobs/DeliverGlobalIdentityOperation.php)

Use the existing tests for current identity fixtures, mail rendering and
dispatch assertions. Identity mail has its own mailer setting and an allowlist
of transports. The delivery tests cover handoff and failure handling for
sensitive mail data.
[Identity delivery tests](../tests/Feature/GlobalIdentityOperationDeliveryTest.php) ·
[Employee lifecycle mail tests](../tests/Feature/Mail/EmployeeLifecycleMailTest.php) ·
[Identity mail transport](../app/Services/GlobalIdentity/IdentityMailDelivery.php)

Production worker placement, supervision and activation belong to deployment.
[API image-consumption contract](https://github.com/SecPal/deployment/blob/main/docs/api-image-consumption.md)
