<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

final class IdentityMailDelivery implements GlobalIdentityDeliveryTransport
{
    public function identity(): string
    {
        return 'laravel-mail';
    }

    public function acceptsV1Key(): bool
    {
        return false;
    }

    public function permitsReplay(CarbonImmutable $firstCommit): bool
    {
        return false;
    }

    public function lookupReference(#[SensitiveParameter] string $reference): ?GlobalIdentityDeliveryResult
    {
        return null;
    }

    public function lookupKey(#[SensitiveParameter] string $key): ?GlobalIdentityDeliveryResult
    {
        return null;
    }

    public function send(
        #[SensitiveParameter] string $recipient,
        #[SensitiveParameter] string $message,
        #[SensitiveParameter] ?string $key,
    ): GlobalIdentityDeliveryResult {
        $sent = $this->mailer()->raw($message, static function (Message $mail) use ($recipient): void {
            $mail->to($recipient)->subject(__('SecPal account security'));
        });

        return new GlobalIdentityDeliveryResult(
            $sent === null ? GlobalIdentityDeliveryOutcome::Unknown : GlobalIdentityDeliveryOutcome::Accepted,
        );
    }

    public function mailer(): Mailer
    {
        $mailer = Mail::mailer($this->mailerName());
        if (! $mailer instanceof Mailer) {
            throw new GlobalIdentityKeyException('Identity mail transport is unavailable.');
        }

        return $mailer;
    }

    public function mailerName(): string
    {
        $name = config()->string('global_identity.mailer');
        $transport = config()->string('mail.mailers.'.$name.'.transport', '');
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'mailgun', 'resend'], true)
            && ! ($transport === 'array' && app()->environment('local', 'testing', 'demo'))) {
            throw new GlobalIdentityKeyException('Identity mail transport is unavailable.');
        }

        return $name;
    }
}
