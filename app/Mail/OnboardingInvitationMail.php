<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * OnboardingInvitationMail sent to pre-contract employees.
 *
 * Contains:
 * - Welcome message
 * - Contract start date
 * - Magic link for account setup
 * - Onboarding checklist
 * - Deadline reminder
 */
class OnboardingInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Employee $employee,
        public User $user,
        public string $plainToken
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $appName = config('app.name');

        return new Envelope(
            subject: is_string($appName) ? __('Welcome to :app_name – Complete Your Onboarding', ['app_name' => $appName]) : __('Welcome – Complete Your Onboarding'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $frontendUrl = config('app.frontend_url');
        $email = $this->employee->email;

        if (! is_string($frontendUrl) || ! is_string($email)) {
            throw new \RuntimeException('Frontend URL or employee email not configured');
        }

        $onboardingUrl = $frontendUrl.'/onboarding/complete?token='.urlencode($this->plainToken).'&email='.urlencode($email);

        return new Content(
            markdown: 'emails.employees.onboarding-invitation',
            with: [
                'onboardingUrl' => $onboardingUrl,
            ],
        );
    }
}
