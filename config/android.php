<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'device_admin_component_name' => (string) env('ANDROID_DEVICE_ADMIN_COMPONENT_NAME', 'app.secpal/.SecPalDeviceAdminReceiver'),
    'signing_certificate_checksum' => (string) env('ANDROID_SIGNING_CERTIFICATE_CHECKSUM', 'm2N7N0F4Q2ZwS0V0bDhlWlU4a1pMRTNwckE3WlJtWm9Kc2J0S2x2dz0='),
    'api_base_url' => (string) env('ANDROID_API_BASE_URL', 'https://api.secpal.dev/v1'),
    'artifact_base_url' => (string) env('ANDROID_ARTIFACT_BASE_URL', 'https://apk.secpal.app'),
];
