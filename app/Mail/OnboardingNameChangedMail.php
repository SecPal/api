<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * OnboardingNameChangedMail sent to HR when employee changes name during onboarding.
 *
 * Notifies HR about name corrections with severity indicators:
 * - Minor (>80% similar): Likely typo correction
 * - Medium (50-80% similar): Significant change (additional name, hyphenation)
 * - Major (<50% similar): Fundamental change (blocked by system)
 */
class OnboardingNameChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param Employee $employee Employee who changed name
     * @param string $oldFirstName Original first name
     * @param string $oldLastName Original last name
     * @param array{allowed: bool, severity: string, similarity: float, message: string|null}|null $firstNameValidation First name validation result
     * @param array{allowed: bool, severity: string, similarity: float, message: string|null}|null $lastNameValidation Last name validation result
     */
    public function __construct(
        public Employee $employee,
        public string $oldFirstName,
        public string $oldLastName,
        public ?array $firstNameValidation,
        public ?array $lastNameValidation
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $appName = config('app.name');

        return new Envelope(
            subject: is_string($appName) ? __('HR Alert: Name Change During Onboarding – :app_name', ['app_name' => $appName]) : __('HR Alert: Name Change During Onboarding'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.hr.onboarding-name-changed',
            with: [
                'employee' => $this->employee,
                'oldFirstName' => $this->oldFirstName,
                'oldLastName' => $this->oldLastName,
                'newFirstName' => $this->employee->first_name,
                'newLastName' => $this->employee->last_name,
                'firstNameChanged' => $this->oldFirstName !== $this->employee->first_name,
                'lastNameChanged' => $this->oldLastName !== $this->employee->last_name,
                'firstNameSeverity' => $this->firstNameValidation['severity'] ?? 'none',
                'lastNameSeverity' => $this->lastNameValidation['severity'] ?? 'none',
                'firstNameSimilarity' => $this->firstNameValidation['similarity'] ?? 100,
                'lastNameSimilarity' => $this->lastNameValidation['similarity'] ?? 100,
            ],
        );
    }
}
