<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityDeliveryKeyUnavailable;
use App\Exceptions\GlobalIdentityKeyException;
use App\Models\GlobalIdentityOperation;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

final readonly class GlobalIdentityDeliveryWorker
{
    public function __construct(
        private GlobalIdentityOperationService $operations,
        private GlobalIdentityOperationSubject $subject,
        private GlobalIdentityDeliveryRenderer $renderer,
        private GlobalIdentityDeliveryTransport $transport,
    ) {}

    public function deliver(string $operationId): void
    {
        $this->requireOutsideTransaction();
        $claim = $this->operations->claim($operationId, $this->transport->identity());
        if ($claim !== null) {
            $this->dispatch($claim);
        }
    }

    public function reconcile(string $operationId): void
    {
        $this->requireOutsideTransaction();
        $claim = $this->operations->forReconciliation($operationId);
        if ($claim === null || $claim->transport_identity !== $this->transport->identity()) {
            return;
        }

        try {
            $reference = $claim->provider_message_reference;
            $result = $reference === null ? null : $this->transport->lookupReference($reference);
            if ($result === null && $claim->uses_idempotency_key && $this->transport->acceptsV1Key()) {
                $key = $this->operations->idempotencyKey($claim);
                $result = $this->transport->lookupKey($key);
            }
            $firstCommit = $claim->dispatch_committed_at;
            $canReplay = $result?->outcome === GlobalIdentityDeliveryOutcome::Absent && $claim->uses_idempotency_key
                && $firstCommit !== null && $this->transport->acceptsV1Key() && $this->transport->permitsReplay($firstCommit);
        } catch (Throwable) {
            $this->operations->recordResult($claim, new GlobalIdentityDeliveryResult(GlobalIdentityDeliveryOutcome::Unknown));

            return;
        } finally {
            if (isset($key)) {
                sodium_memzero($key);
            }
        }

        if ($result === null) {
            return;
        }
        if ($canReplay) {
            $replay = $this->operations->claimReplay($claim, $result);
            if ($replay !== null) {
                $this->dispatch($replay);
            }

            return;
        }
        $this->operations->recordResult($claim, $result);
    }

    private function dispatch(#[SensitiveParameter] GlobalIdentityOperation $claim): void
    {
        try {
            try {
                $context = $claim->context();
                $token = $this->operations->openToken($claim);
                $address = $this->operations->openRecipient($claim)
                    ?? GlobalEmailAddress::parse($this->subject->recipient($context));
                $recipient = $address->transport();
                $message = $this->renderer->render($context, $token);
                $key = $this->transport->acceptsV1Key() ? $this->operations->idempotencyKey($claim) : null;
            } catch (GlobalIdentityDeliveryKeyUnavailable) {
                $this->operations->preparationFailed($claim, keyUnavailable: true);

                return;
            } catch (Throwable) {
                $this->operations->preparationFailed($claim);

                return;
            }

            if ($claim->transport_identity !== $this->transport->identity() || ! $this->operations->commitDispatch($claim, $key !== null)) {
                return;
            }
            $this->requireOutsideTransaction();
            try {
                $result = $this->transport->send($recipient, $message, $key);
            } catch (Throwable) {
                $result = new GlobalIdentityDeliveryResult(GlobalIdentityDeliveryOutcome::Unknown);
            }
            $this->operations->recordResult($claim, $result);
        } finally {
            if (isset($token)) {
                sodium_memzero($token);
            }
            if (isset($recipient)) {
                sodium_memzero($recipient);
            }
            if (isset($message)) {
                sodium_memzero($message);
            }
            if (isset($key)) {
                sodium_memzero($key);
            }
        }
    }

    private function requireOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new GlobalIdentityKeyException('Global identity transport requires a committed database state.');
        }
    }
}
