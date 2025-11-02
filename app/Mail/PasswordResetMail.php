<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password Reset Email Mailable.
 *
 * Security Notes:
 * - Token is only included in email body (never in logs)
 * - Email is queued for async sending
 * - Token expires in 60 minutes (must match AuthController::PASSWORD_RESET_TOKEN_EXPIRY_MINUTES)
 * - Reset URL uses HTTPS in production
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  User  $user  The user requesting password reset
     * @param  string  $token  The plain-text reset token (not hashed)
     */
    public function __construct(
        public User $user,
        public string $token
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset Your SecPal Password',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Generate reset URL
        $resetUrl = $this->buildResetUrl();

        return new Content(
            markdown: 'emails.password-reset',
            with: [
                'user' => $this->user,
                'resetUrl' => $resetUrl,
                'expiresInMinutes' => 60, // Must match AuthController::PASSWORD_RESET_TOKEN_EXPIRY_MINUTES
            ],
        );
    }

    /**
     * Build the password reset URL.
     *
     * Security: Uses HTTPS in production, token is URL-encoded.
     */
    private function buildResetUrl(): string
    {
        /** @var string $baseUrl */
        $baseUrl = config('app.url');
        $email = urlencode($this->user->email);
        $token = urlencode($this->token);

        // Frontend will handle the reset form
        // Example: https://secpal.app/auth/password-reset?email=user@example.com&token=xxx
        return "{$baseUrl}/auth/password-reset?email={$email}&token={$token}";
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
