<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Contracts;

use App\Services\TenantKekRotationPlan;

interface TenantKekRotationAuthority
{
    /**
     * Return the exact maintenance scope after verifying external approval,
     * retained recovery material and the authorized read-only key inputs.
     */
    public function approvedPlan(string $operationId): TenantKekRotationPlan;
}
