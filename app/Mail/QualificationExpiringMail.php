<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\EmployeeQualification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * QualificationExpiringMail sent 30 days before qualification expiry.
 *
 * Contains:
 * - Qualification name
 * - Expiry date
 * - Renewal instructions
 * - HR contact
 */
class QualificationExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public EmployeeQualification $qualification,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $employee = $this->qualification->employee;
        $qualName = $this->qualification->qualification->name ?? __('emails.qualification_expiring.qualification_fallback');

        return new Envelope(
            subject: __('emails.qualification_expiring.subject', ['qualification_name' => $qualName]),
            to: $employee?->email ? [$employee->email] : [],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.employees.qualification-expiring',
            with: [
                'employee' => $this->qualification->employee,
                'qualification' => $this->qualification,
            ],
        );
    }
}
