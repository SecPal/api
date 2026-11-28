<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class TenantKeyMaintenanceException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(1, 'Tenant key maintenance is in progress.');
    }
}
