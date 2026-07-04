<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

return [
    'account_deactivated' => [
        'subject' => 'Konto deaktiviert',
    ],
    'compliance_alert' => [
        'subject' => 'Compliance-Dokumente erfordern Aufmerksamkeit: :severity',
        'severities' => [
            'warning' => 'laufen bald ab',
            'critical' => 'kritisch',
            'expired' => 'abgelaufen',
        ],
    ],
    'contract_ending_soon' => [
        'subject' => 'Ihr Vertrag endet bald',
    ],
    'qualification_expiring' => [
        'qualification_fallback' => 'Qualifikation',
        'subject' => 'Qualifikation läuft bald ab: :qualification_name',
    ],
    'welcome_active' => [
        'subject' => 'Willkommen im Team!',
    ],
];
