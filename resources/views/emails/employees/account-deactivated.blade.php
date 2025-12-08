{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# {{ __('Account Deactivated') }}

{{ __('Hello :first_name,', ['first_name' => $employee->first_name]) }}

{{ __('Your user account has been deactivated.') }}

@if ($employee->termination_date)
**{{ __('Last Working Day') }}:** {{ $employee->termination_date->format('d.m.Y') }}
@endif

@if ($employee->last_working_day)
**{{ __('Last Day of Attendance') }}:** {{ $employee->last_working_day->format('d.m.Y') }}
@endif

## {{ __('What This Means') }}

- {{ __('You can no longer log in to the system') }}
- {{ __('All active sessions have been ended') }}
- {{ __('Your access to company data has been revoked') }}

## {{ __('Important Reminders') }}

{{ __('Please remember to:') }}
- {{ __('Return all company property (keys, badges, devices)') }}
- {{ __('Destroy confidential documents') }}
- {{ __('Maintain confidentiality obligations') }}

Bei Fragen zur Vertragsbeendigung oder zu offenen Fragen erreichen Sie uns unter {{ config('mail.from.address') }}.

Wir danken Ihnen für Ihre Mitarbeit und wünschen Ihnen alles Gute für die Zukunft.

Ihr HR-Team

---

Diese E-Mail wurde automatisch generiert.
</x-mail::message>
