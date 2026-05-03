{{-- SPDX-FileCopyrightText: 2025 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<x-mail::message>
# {{ __('Welcome to :app_name!', ['app_name' => config('app.name')]) }}

{{ __('Hello :first_name,', ['first_name' => $employee->first_name]) }}

{{ __('we are excited to welcome you to our team soon!') }}

{{ __('Your contract starts on **:date**.', ['date' => $employee->contract_start_date->format('d.m.Y')]) }}

{{ __('To prepare for your first day, please complete the onboarding process in our portal:') }}

<x-mail::button :url="$onboardingUrl">
{{ __('Start Onboarding') }}
</x-mail::button>

## {{ __('What to expect:') }}

### {{ __('Required information') }}

- {{ __('Personal information for onboarding (including gender and nationalities)') }}

### {{ __('Optional sections') }}

- {{ __('Bank account details for salary payment') }}
- {{ __('Emergency contacts') }}
- {{ __('Tax identification number (Steuer-ID)') }}

### {{ __('Supporting documents') }}

- {{ __('On every onboarding step you can upload PDF, JPG, or PNG files up to 10 MB — choose contract, identity document, or banking verification as appropriate.') }}

{{ __('**Important:** Please complete the onboarding by **:deadline** at the latest.', ['deadline' => $employee->contract_start_date->copy()->subDays(3)->format('d.m.Y')]) }}

{{ __('If you have any questions, please contact us at :email.', ['email' => config('mail.from.address')]) }}

{{ __('Best regards,') }}
{{ __('Your HR Team') }}

---

{{ __('This email was generated automatically. Please do not reply directly to this email.') }}
</x-mail::message>
