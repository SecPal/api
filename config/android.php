<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'device_admin_component_name' => (string) env('ANDROID_DEVICE_ADMIN_COMPONENT_NAME', 'app.secpal/.SecPalDeviceAdminReceiver'),
    'signing_certificate_checksum' => (string) env('ANDROID_SIGNING_CERTIFICATE_CHECKSUM', 'm2N7N0F4Q2ZwS0V0bDhlWlU4a1pMRTNwckE3WlJtWm9Kc2J0S2x2dz0='),
    'signing_certificate_sha256_fingerprint' => (string) env('ANDROID_SIGNING_CERTIFICATE_SHA256_FINGERPRINT', 'C3:E9:FD:07:69:F3:34:9B:B0:B0:56:BA:E6:69:47:23:40:E1:CB:28:66:26:DE:30:C9:C9:FA:F9:5F:1E:47:B5'),
    'api_base_url' => (string) env('ANDROID_API_BASE_URL', 'https://api.secpal.dev/v1'),
    'artifact_base_url' => (string) env('ANDROID_ARTIFACT_BASE_URL', 'https://apk.secpal.app'),
];
