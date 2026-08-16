<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'account_deactivated' => [
        'subject' => 'Account Deactivated',
    ],
    'compliance_alert' => [
        'subject' => 'Compliance documents require attention: :severity',
        'severities' => [
            'warning' => 'expiring soon',
            'critical' => 'critical',
            'expired' => 'expired',
        ],
    ],
    'contract_ending_soon' => [
        'subject' => 'Your Contract Ends Soon',
    ],
    'qualification_expiring' => [
        'qualification_fallback' => 'Qualification',
        'subject' => 'Qualification Expiring Soon: :qualification_name',
    ],
    'welcome_active' => [
        'subject' => 'Welcome to the Team!',
    ],
];
