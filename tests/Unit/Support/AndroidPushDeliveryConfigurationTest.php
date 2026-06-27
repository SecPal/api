<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Support\AndroidPushDeliveryConfiguration;

test('missing fields include invalid fcm endpoint overrides', function (): void {
    config([
        'services.fcm.project_id' => 'customer-owned-project',
        'services.fcm.client_email' => 'firebase-adminsdk@customer-owned-project.iam.gserviceaccount.com',
        'services.fcm.private_key' => "-----BEGIN PRIVATE KEY-----\nplaceholder\n-----END PRIVATE KEY-----\n",
        'services.fcm.token_uri' => 'https://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token',
        'services.fcm.api_base_url' => 'https://push-proxy.internal.example.test',
    ]);

    $configuration = new AndroidPushDeliveryConfiguration;

    expect($configuration->missingFields())->toBe([
        'services.fcm.token_uri (present but invalid; must target https://oauth2.googleapis.com/token)',
        'services.fcm.api_base_url (present but invalid; must target https://fcm.googleapis.com)',
    ]);
});

test('canonical google fcm endpoint overrides remain accepted', function (): void {
    config([
        'services.fcm.project_id' => 'customer-owned-project',
        'services.fcm.client_email' => 'firebase-adminsdk@customer-owned-project.iam.gserviceaccount.com',
        'services.fcm.private_key' => "-----BEGIN PRIVATE KEY-----\nplaceholder\n-----END PRIVATE KEY-----\n",
        'services.fcm.token_uri' => 'https://oauth2.googleapis.com/token/',
        'services.fcm.api_base_url' => 'https://fcm.googleapis.com/',
    ]);

    $configuration = new AndroidPushDeliveryConfiguration;

    expect($configuration->missingFields())->toBe([])
        ->and($configuration->tokenUri())->toBe('https://oauth2.googleapis.com/token')
        ->and($configuration->messageEndpoint())->toBe('https://fcm.googleapis.com/v1/projects/customer-owned-project/messages:send');
});
