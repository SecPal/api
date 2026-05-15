<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Support;

/**
 * Rolling calendar lookback (years) shared by BWR export address-history validation
 * and onboarding residential-address-history submit validation.
 */
final class AddressHistoryLookback
{
    public const YEARS = 5;
}
