{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# {{ __('Your Contract Ends Soon') }}

{{ __('Hello :first_name,', ['first_name' => $employee->first_name]) }}

{{ __('We would like to remind you that your employment contract ends soon.') }}

**{{ __('Contract End Date') }}:** {{ $employee->termination_date->format('d.m.Y') }}

@if ($employee->last_working_day)
**{{ __('Last Working Day') }}:** {{ $employee->last_working_day->format('d.m.Y') }}
@endif

## {{ __('Would You Like to Continue?') }}

{{ __('If you are interested in extending your contract, please contact your supervisor or the HR department promptly.') }}

## {{ __('Next Steps') }}

{{ __('If your contract will not be extended:') }}
- {{ __('Your system access will be automatically deactivated on :date', ['date' => $employee->termination_date->format('d.m.Y')]) }}
- {{ __('Please schedule an appointment to return company property') }}
- {{ __('We will send you your employment certificate and other documents') }}

{{ __('If you have any questions, please contact us at :email.', ['email' => config('mail.from.address')]) }}

{{ __('Best regards,') }}
{{ __('Your HR Team') }}

---

{{ __('This email was generated automatically.') }}
</x-mail::message>
