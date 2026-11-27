<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use SensitiveParameter;

final class IdentityLinkRenderer implements GlobalIdentityDeliveryRenderer
{
    public function render(GlobalIdentityOperationContext $operation, #[SensitiveParameter] string $token): string
    {
        $path = match ($operation->type) {
            GlobalIdentityOperationType::PasswordReset => '/reset-password',
            GlobalIdentityOperationType::EmailVerification => '/verify-email',
            GlobalIdentityOperationType::PendingEmailChange => '/verify-email-change',
            GlobalIdentityOperationType::Invitation => throw new GlobalIdentityKeyException('No invitation renderer is active.'),
        };
        $url = rtrim(config()->string('app.frontend_url'), '/').$path.'#'.http_build_query([
            'operationId' => $operation->id,
            'token' => $token,
        ], encoding_type: PHP_QUERY_RFC3986);

        return __('Open this single-use SecPal link to continue.')."\n\n".$url;
    }
}
