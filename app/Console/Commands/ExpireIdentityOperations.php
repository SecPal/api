<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GlobalIdentityOperation;
use App\Services\GlobalIdentity\GlobalIdentityOperationService;
use App\Services\GlobalIdentity\GlobalIdentityOperationType;
use App\Services\GlobalIdentity\GlobalIdentityTerminalReason;
use Illuminate\Console\Command;

final class ExpireIdentityOperations extends Command
{
    protected $signature = 'identity:expire-operations';

    protected $description = 'Release expired identity proof and pending email reservations';

    public function handle(GlobalIdentityOperationService $operations): int
    {
        GlobalIdentityOperation::query()->where('expires_at', '<=', now())
            ->outstanding()
            ->where('operation_type', '!=', GlobalIdentityOperationType::Invitation)
            ->lazyById()->each(static function (GlobalIdentityOperation $operation) use ($operations): void {
                $operations->invalidate($operation->id, GlobalIdentityTerminalReason::Expired);
            });

        return self::SUCCESS;
    }
}
