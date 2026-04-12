<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeComplianceAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{type: string, label: string, expiry: string, status: string, days_until_expiry: int}>  $documents
     */
    public function __construct(
        public Employee $employee,
        public array $documents,
        public string $severity,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('emails.compliance_alert.subject', [
                'severity' => __('emails.compliance_alert.severities.'.$this->severity),
            ]),
            to: $this->employee->email ? [$this->employee->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.employees.compliance-alert',
            with: [
                'employee' => $this->employee,
                'documents' => $this->documents,
                'severity' => $this->severity,
            ],
        );
    }
}
