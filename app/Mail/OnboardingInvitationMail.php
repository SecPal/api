<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Password;

/**
 * OnboardingInvitationMail sent to pre-contract employees.
 *
 * Contains:
 * - Welcome message
 * - Contract start date
 * - Password reset link
 * - Onboarding checklist
 * - Deadline reminder
 */
class OnboardingInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Employee $employee,
        public User $user
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $appName = config('app.name');

        return new Envelope(
            subject: is_string($appName) ? "Willkommen bei {$appName} - Onboarding abschließen" : 'Willkommen - Onboarding abschließen',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Generate password reset token
        $token = Password::createToken($this->user);
        $frontendUrl = config('app.frontend_url');
        $email = $this->employee->email;

        if (! is_string($frontendUrl) || ! is_string($email)) {
            throw new \RuntimeException('Frontend URL or employee email not configured');
        }

        $resetUrl = $frontendUrl.'/password/reset?token='.$token.'&email='.urlencode($email);

        return new Content(
            markdown: 'emails.employees.onboarding-invitation',
            with: [
                'resetUrl' => $resetUrl,
            ],
        );
    }
}
