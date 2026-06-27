<!-- SPDX-FileCopyrightText: 2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Firebase Push Bootstrap Audit

This checklist exists because `BOOTSTRAP_ANDROID_PUSH_PUBLIC_API_KEY` is intentionally public client configuration, not a backend secret. The SecPal source tree can prove what the API publishes and what it keeps server-side, but it cannot prove the current Google Cloud console state for a live customer deployment.

Run this audit for each deployment that enables `BOOTSTRAP_ANDROID_PUSH_ENABLED=true`, and record the result outside version control with the deployment owner, audit date, Google Cloud project id, and API key display name.

## What The Source Tree Proves

- `GET /v1/bootstrap` only publishes the Android client runtime fields `api_key`, `project_id`, `application_id`, and `sender_id`.
- Backend-only FCM delivery credentials stay in `ANDROID_PUSH_FCM_*` and are not exposed through bootstrap.
- Browser push bootstrap uses the Web Push VAPID public key and does not require Firebase client metadata.
- Focused regression coverage proves that bootstrap omits backend-only Android and browser push secrets.

Relevant files:

- [`config/bootstrap.php`](../../config/bootstrap.php)
- [`app/Support/NotificationChannelRuntimeConfiguration.php`](../../app/Support/NotificationChannelRuntimeConfiguration.php)
- [`tests/Feature/Api/V1/BootstrapApiTest.php`](../../tests/Feature/Api/V1/BootstrapApiTest.php)

## Per-Deployment Google Cloud Audit

### 1. Identify The Exact Project And Key

- Match `BOOTSTRAP_ANDROID_PUSH_PUBLIC_PROJECT_ID` to the Firebase / Google Cloud project that owns the Android runtime.
- In Google Cloud Console, open `APIs & Services -> Credentials`.
- Find the API key whose value matches `BOOTSTRAP_ANDROID_PUSH_PUBLIC_API_KEY`.
- Prefer a Firebase-managed Android client key or a dedicated replacement key used only for this Android runtime.

### 2. Confirm API Restrictions Are Enabled

- Open the key and make sure `API restrictions` is set to `Restrict key`, not `Don't restrict key`.
- The allowlist must contain only Firebase-related APIs needed by the SecPal Android client runtime.
- Inference from the current SecPal Android client footprint plus Firebase's API-key guidance: the service-specific APIs SecPal should need are `Firebase Installations API` (`firebaseinstallations.googleapis.com`) and `FCM Registration API` (`fcmregistrations.googleapis.com`).
- Firebase may auto-populate additional Firebase-only entries on auto-created keys. If you remove any Firebase-added entry, test bootstrap, token acquisition, login, token rotation, and push delivery in staging before changing production.

### 3. Confirm Unrelated APIs Are Not Allowed On The Key

- `Generative Language API` must not appear in the key allowlist.
- `Maps JavaScript API`, `Places API`, and other Google Maps Platform APIs must not appear in the key allowlist.
- No non-Firebase Google API should share this public SecPal bootstrap key.
- If the project uses Gemini, Maps, Places, or other unrelated Google APIs for separate workloads, those workloads must use separate keys with their own restrictive allowlists.

### 4. Confirm Enabled Services And Quotas Are Narrow

- In `IAM & Admin -> Quotas & System Limits`, review the quotas that matter for this deployment's push path.
- At minimum, inspect `Firebase Installations API`, `FCM Registration API`, and server-side `Firebase Cloud Messaging API` quota usage.
- Where Google Cloud allows quota overrides or caps, set values that match the expected rollout size and operational envelope for the tenant instead of leaving abuse headroom unnecessarily high.
- Re-check quotas after large rollout changes, Android release promotions, or customer onboarding spikes.

### 5. Confirm Billing Budgets And Alerts Exist

- In `Billing -> Budgets & alerts`, verify the linked Cloud Billing account has at least one active budget covering the owning project or billing account.
- Configure threshold alert emails for the people who can respond operationally.
- If your operations model uses automation, add Pub/Sub or Cloud Monitoring recipients for the same budget.
- Remember that Google Cloud budgets alert on spend but do not stop usage automatically.

### 6. Record The Audit Result

- Record the audit date.
- Record the Google Cloud project id.
- Record the API key display name and last four characters.
- Record whether the allowlist was already compliant or needed correction.
- Record the quota pages reviewed and the active budget names.
- Attach console screenshots or exported evidence in your customer ops system.

## App Check Evaluation For Current SecPal Clients

- Current SecPal browser push does not use Firebase client SDKs. It uses Web Push plus VAPID, so Firebase App Check does not apply to that client path.
- Current SecPal Android push bootstrap initializes Firebase Messaging at runtime from public metadata, but the shipped Android client does not currently integrate the Firebase App Check SDK or Play Integrity attestation flow.
- Firebase's current App Check supported-service list does not include Cloud Messaging. SecPal's API also does not currently validate App Check tokens on notification-installation endpoints or bootstrap.
- Result: App Check should be recorded as `not currently enforceable` for the public SecPal push bootstrap path today.
- Revisit this only after a coordinated client-and-server change set adds Android App Check token issuance, backend validation, rollout telemetry, and an operator migration plan.

## Primary References

- Firebase API keys: <https://firebase.google.com/docs/projects/api-keys>
- Firebase FAQ on safe public Firebase keys: <https://firebase.google.com/support/faq>
- Firebase App Check overview and supported services: <https://firebase.google.com/docs/app-check>
- Google Cloud budgets and alerts: <https://docs.cloud.google.com/billing/docs/how-to/budgets>
- Google Cloud quota review: <https://docs.cloud.google.com/docs/quotas/view-manage>
- Google Cloud API usage caps: <https://docs.cloud.google.com/apis/docs/capping-api-usage>
