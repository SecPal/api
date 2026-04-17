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

class BwrIdDocumentAutoDeletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Only dispatch the queue job after the surrounding DB transaction commits.
     * Prevents a spurious HR notification when the BWR activation rolls back.
     */
    public bool $afterCommit = true;

    public function __construct(
        public Employee $employee,
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name');

        return new Envelope(
            subject: is_string($appName)
                ? __('HR Alert: ID Document Copy Deleted After BWR Approval - :app_name', ['app_name' => $appName])
                : __('HR Alert: ID Document Copy Deleted After BWR Approval'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.hr.bwr-id-document-auto-deleted',
            with: [
                'employee' => $this->employee,
                'deletionReason' => __('The stored ID document copy was deleted automatically because BWR approval made continued storage unnecessary.'),
                'legalBasis' => 'GDPR Art. 5(1)(e) - Storage Limitation',
            ],
        );
    }
}
