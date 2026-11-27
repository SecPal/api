<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Contracts;

use App\Services\GlobalIdentity\GlobalIdentityRotationPlan;

interface GlobalIdentityRotationAuthority
{
    /**
     * Return the exact approved scope after validating the required external
     * backup, recovery, and operational evidence for this transition.
     * Reject missing, stale, or unauthorized evidence.
     */
    public function approvedPlan(string $operationId, string $action): GlobalIdentityRotationPlan;
}
