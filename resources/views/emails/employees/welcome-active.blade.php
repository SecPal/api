{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution --}}

<x-mail::message>
# {{ __('Welcome to the Team!') }}

{{ __('Hello :first_name,', ['first_name' => $employee->first_name]) }}

{{ __('welcome to :app_name! We are happy that you are starting your first day with us today.', ['app_name' => config('app.name')]) }}

## {{ __('Your First Day') }}

**{{ __('Position') }}:** {{ $employee->position }}
**{{ __('Organizational Unit') }}:** {{ $employee->organizationalUnit->name ?? __('Not assigned') }}
**{{ __('Employee Number') }}:** {{ $employee->employee_number }}

## {{ __('Important Information') }}

{{ __('Your access to our system has been activated. You can now log in with your email address (:email).', ['email' => $employee->email]) }}

<x-mail::button :url="config('app.frontend_url')">
{{ __('Go to Portal') }}
</x-mail::button>

## {{ __('First Steps') }}

1. {{ __('Familiarize yourself with our portal') }}
2. {{ __('Verify your personal information') }}
3. {{ __('Upload any missing documents if necessary') }}
4. {{ __('Contact your supervisor if you have questions') }}

{{ __('If you have any questions, please contact us at :email.', ['email' => config('mail.from.address')]) }}

{{ __('Best regards and good luck!') }}
{{ __('Your HR Team') }}

---

{{ __('This email was generated automatically.') }}
</x-mail::message>
