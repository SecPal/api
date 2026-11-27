<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use Carbon\CarbonImmutable;
use SensitiveParameter;

interface GlobalIdentityDeliveryTransport
{
    /** Stable opaque identity for this provider and its delivery account. */
    public function identity(): string;

    /** The provider accepts the complete 43-character V1 key. */
    public function acceptsV1Key(): bool;

    /** The provider guarantees idempotent repetition within the original attempt's window. */
    public function permitsReplay(CarbonImmutable $firstCommit): bool;

    public function send(
        #[SensitiveParameter] string $recipient,
        #[SensitiveParameter] string $message,
        #[SensitiveParameter] ?string $key,
    ): GlobalIdentityDeliveryResult;

    /** Use the provider's authoritative status source or return null if unavailable. */
    public function lookupReference(#[SensitiveParameter] string $reference): ?GlobalIdentityDeliveryResult;

    /** Use the provider's authoritative status source or return null if unavailable. */
    public function lookupKey(#[SensitiveParameter] string $key): ?GlobalIdentityDeliveryResult;
}
