<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

$frontendUrl = (string) env('FRONTEND_URL', 'https://app.secpal.dev');
$frontendOriginEntries = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('PASSKEY_ALLOWED_ORIGINS', $frontendUrl)),
)));

$frontendHost = parse_url($frontendUrl, PHP_URL_HOST);

// Dedicated fallback secret (preferred). When unset/blank the deterministic
// fallback `allow_credentials` HMAC is derived from APP_KEY, which means an
// APP_KEY rotation will silently change the phantom credential IDs issued for
// unknown / unenrolled emails. Set PASSKEY_AUTHENTICATION_FALLBACK_SECRET
// independently when stable fallback IDs across APP_KEY rotations are desired.
$dedicatedFallbackSecret = (string) env('PASSKEY_AUTHENTICATION_FALLBACK_SECRET', '');
$appKeyFallbackSecret = (string) env('APP_KEY', '');
$resolvedFallbackSecret = $dedicatedFallbackSecret !== '' ? $dedicatedFallbackSecret : $appKeyFallbackSecret;

return [
    'rp_id' => (string) env('PASSKEY_RP_ID', is_string($frontendHost) ? $frontendHost : 'app.secpal.dev'),
    'rp_name' => (string) env('PASSKEY_RP_NAME', env('APP_NAME', 'SecPal')),
    'allowed_origins' => $frontendOriginEntries,
    'allow_subdomains' => filter_var(env('PASSKEY_ALLOW_SUBDOMAINS', false), FILTER_VALIDATE_BOOL),
    'challenge_timeout_ms' => (int) env('PASSKEY_CHALLENGE_TIMEOUT_MS', 60000),
    'challenge_expiration_minutes' => (int) env('PASSKEY_CHALLENGE_EXPIRATION_MINUTES', 10),
    'attestation' => (string) env('PASSKEY_ATTESTATION', 'none'),
    'authentication_fallback_secret' => $resolvedFallbackSecret,
    'authentication_fallback_uses_app_key' => $dedicatedFallbackSecret === '' && $appKeyFallbackSecret !== '',
    'user_verification' => (string) env('PASSKEY_USER_VERIFICATION', 'preferred'),
    'resident_key' => (string) env('PASSKEY_RESIDENT_KEY', 'preferred'),
    'require_resident_key' => filter_var(env('PASSKEY_REQUIRE_RESIDENT_KEY', false), FILTER_VALIDATE_BOOL),
    'authenticator_attachment' => env('PASSKEY_AUTHENTICATOR_ATTACHMENT'),
];
