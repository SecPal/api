<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryWorker;
use App\Services\GlobalIdentity\GlobalIdentityOperationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverGlobalIdentityOperation implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $operationId)
    {
        if (! GlobalIdentityOperationContext::isCanonicalId($operationId)) {
            throw new GlobalIdentityKeyException('Invalid global identity operation identifier.');
        }
        $this->afterCommit();
    }

    public function handle(GlobalIdentityDeliveryWorker $worker): void
    {
        $worker->deliver($this->operationId);
    }
}
